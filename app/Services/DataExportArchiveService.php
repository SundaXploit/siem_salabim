<?php

namespace App\Services;

use App\Models\DataExport;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DataExportArchiveService
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'alerts' => [
            'id', 'wazuh_alert_id', 'source_cluster', 'source_index', 'source_id', 'source_identity_hash',
            'rule_description', 'src_ip', 'dst_ip', 'agent_name', 'rule_id',
            'rule_level', 'status', 'first_seen_at', 'raw_data', 'created_at',
            'updated_at',
        ],
        'alert_ai_analyses' => [
            'id', 'alert_id', 'requested_by_user_id', 'verdict', 'confidence',
            'summary', 'supporting_indicators', 'legitimate_indicators',
            'recommended_checks', 'limitations', 'model', 'duration_ms',
            'generated_at', 'created_at', 'updated_at',
        ],
        'alert_triages' => [
            'id', 'alert_id', 'user_id', 'action', 'reason', 'created_at',
        ],
        'notification_logs' => [
            'id', 'alert_id', 'user_id', 'chat_id', 'message',
            'response_status', 'sent_at',
        ],
    ];

    /**
     * Build an archive with bounded memory, upload it to private storage, and
     * verify the durable object before marking the export as usable.
     *
     * @return array<string, mixed>
     */
    public function generate(DataExport $export): array
    {
        $this->assertPrivateStorageDisk($export->storage_disk);
        $storagePath = $this->archivePath($export);
        $manifestPath = $this->manifestPath($export);
        $disk = Storage::disk($export->storage_disk);
        $temporaryPath = $this->createTemporaryFile();

        try {
            $snapshot = $this->withConsistentSnapshot(function () use ($export, $temporaryPath): array {
                $snapshotAt = now();
                $boundaries = $this->captureBoundaries($export);

                if (($boundaries['alerts'] ?? 0) < 1) {
                    throw new RuntimeException('Tidak ada alert pada rentang ekspor.');
                }

                $export->forceFill([
                    'source_snapshot_at' => $snapshotAt,
                    'snapshot_boundaries' => $boundaries,
                ]);

                return [
                    ...$this->writeArchive($export, $temporaryPath),
                    'snapshot_at' => $snapshotAt,
                    'snapshot_boundaries' => $boundaries,
                ];
            });

            // Persist snapshot metadata after the read-only snapshot closes.
            // On SQLite this avoids holding a write transaction for the full
            // duration of a potentially large streamed archive.
            $export->forceFill([
                'source_snapshot_at' => $snapshot['snapshot_at'],
                'snapshot_boundaries' => $snapshot['snapshot_boundaries'],
            ])->save();

            $localChecksum = hash_file('sha256', $temporaryPath);
            $localSize = filesize($temporaryPath);
            if ($localChecksum === false || $localSize === false) {
                throw new RuntimeException('Checksum file ekspor tidak dapat dihitung.');
            }

            $archiveStream = fopen($temporaryPath, 'rb');
            if ($archiveStream === false) {
                throw new RuntimeException('File ekspor sementara tidak dapat dibaca.');
            }

            try {
                $stored = $disk->put($storagePath, $archiveStream, ['visibility' => 'private']);
            } finally {
                fclose($archiveStream);
            }

            if (! $stored) {
                throw new RuntimeException('File ekspor tidak dapat disimpan ke storage privat.');
            }

            $storedChecksum = $this->hashStoredFile($export->storage_disk, $storagePath);
            $storedSize = $disk->size($storagePath);
            if (! hash_equals($localChecksum, $storedChecksum) || $storedSize !== $localSize) {
                throw new RuntimeException('Verifikasi checksum atau ukuran file ekspor gagal.');
            }

            $manifest = $this->buildManifest(
                $export->fresh(),
                $storagePath,
                $localChecksum,
                $localSize,
                $snapshot,
            );
            $manifestJson = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            if (! $disk->put($manifestPath, $manifestJson, ['visibility' => 'private'])) {
                throw new RuntimeException('Manifest ekspor tidak dapat disimpan.');
            }

            $storedManifest = $disk->get($manifestPath);
            $manifestChecksum = hash('sha256', $storedManifest);
            if (! hash_equals(hash('sha256', $manifestJson), $manifestChecksum)) {
                throw new RuntimeException('Verifikasi checksum manifest ekspor gagal.');
            }

            $export->forceFill([
                'status' => 'verified',
                'storage_path' => $storagePath,
                'manifest_path' => $manifestPath,
                'checksum' => $localChecksum,
                'manifest_checksum' => $manifestChecksum,
                'file_size' => $localSize,
                'record_counts' => $snapshot['record_counts'],
                'source_fingerprint' => $snapshot['source_fingerprint'],
                'verified_at' => now(),
                'error_message' => null,
            ])->save();

            return $manifest;
        } catch (\Throwable $exception) {
            $disk->delete([$storagePath, $manifestPath]);
            throw $exception;
        } finally {
            $this->deleteTemporaryFile($temporaryPath);
        }
    }

    /**
     * Keep every chunk and related table on one database snapshot. PostgreSQL
     * otherwise uses a new READ COMMITTED snapshot for each statement, while
     * MySQL needs the transaction characteristic set before BEGIN.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withConsistentSnapshot(callable $callback): array
    {
        /** @var Connection $connection */
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($connection->transactionLevel() !== 0 && in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException('Ekspor konsisten tidak dapat dimulai di dalam transaksi lain.');
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // SET TRANSACTION applies only to the next transaction and does
            // not leak a changed session default into this long-lived worker.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $connection->transaction($callback, 1);
        }

        if ($driver === 'pgsql') {
            return $connection->transaction(function () use ($connection, $callback): array {
                // This must be the first statement after BEGIN on PostgreSQL.
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

                return $callback();
            }, 3);
        }

        return $connection->transaction($callback, 3);
    }

    /**
     * Reject disks that are explicitly public or backed by a web-accessible
     * local directory. Exports may contain credentials and complete raw logs.
     */
    public function assertPrivateStorageDisk(string $diskName): void
    {
        $diskName = trim($diskName);
        $diskConfig = config("filesystems.disks.{$diskName}");

        if ($diskName === '' || $diskName === 'public' || ! is_array($diskConfig)) {
            throw new RuntimeException('Disk ekspor privat tidak valid.');
        }

        if (($diskConfig['visibility'] ?? null) === 'public') {
            throw new RuntimeException('Disk ekspor tidak boleh memiliki visibility public.');
        }

        if (($diskConfig['driver'] ?? null) !== 'local') {
            return;
        }

        $root = trim((string) ($diskConfig['root'] ?? ''));
        if ($root === '' || $this->pathIsWithin($root, storage_path('app/public')) || $this->pathIsWithin($root, public_path())) {
            throw new RuntimeException('Root disk ekspor lokal tidak boleh berada di direktori publik.');
        }
    }

    /**
     * Re-check both the manifest and archive object from durable storage.
     *
     * @return array<string, mixed>
     */
    public function verifyArchive(DataExport $export): array
    {
        $export->refresh();
        if (! $export->isVerified() || ! $export->storage_path || ! $export->manifest_path) {
            throw new RuntimeException('Ekspor belum berstatus terverifikasi.');
        }

        $this->assertPrivateStorageDisk($export->storage_disk);
        $disk = Storage::disk($export->storage_disk);
        if (! $disk->exists($export->storage_path) || ! $disk->exists($export->manifest_path)) {
            throw new RuntimeException('File atau manifest ekspor tidak ditemukan di storage privat.');
        }

        $manifestJson = $disk->get($export->manifest_path);
        if (! hash_equals((string) $export->manifest_checksum, hash('sha256', $manifestJson))) {
            throw new RuntimeException('Checksum manifest ekspor tidak cocok.');
        }

        $manifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
        $expected = [
            'export_uuid' => $export->uuid,
            'archive.path' => $export->storage_path,
            'archive.sha256' => $export->checksum,
            'archive.bytes' => $export->file_size,
            'source.fingerprint' => $export->source_fingerprint,
        ];
        foreach ($expected as $key => $value) {
            if (data_get($manifest, $key) !== $value) {
                throw new RuntimeException("Isi manifest ekspor tidak cocok pada {$key}.");
            }
        }

        // JSON object key order is not stable across database engines. MySQL,
        // for example, may normalize an object's key order when storing it in
        // a native JSON column. Compare a validated canonical representation
        // so the same entity/count pairs remain portable across engines.
        if ($this->normalizeRecordCounts(data_get($manifest, 'source.record_counts'))
            !== $this->normalizeRecordCounts($export->record_counts)) {
            throw new RuntimeException('Isi manifest ekspor tidak cocok pada source.record_counts.');
        }

        $storedSize = $disk->size($export->storage_path);
        $storedChecksum = $this->hashStoredFile($export->storage_disk, $export->storage_path);
        if ($storedSize !== $export->file_size || ! hash_equals((string) $export->checksum, $storedChecksum)) {
            throw new RuntimeException('Integritas file ekspor tidak lolos verifikasi ulang.');
        }

        return $manifest;
    }

    /**
     * Confirm that all exported rows still match the source snapshot. A purge
     * is refused if anything included in the archive changed after export.
     */
    public function verifySourceSnapshot(DataExport $export): void
    {
        $current = $this->fingerprintSource($export);
        if ($this->normalizeRecordCounts($current['record_counts'])
            !== $this->normalizeRecordCounts($export->record_counts)) {
            throw new RuntimeException('Jumlah record sumber berubah sejak backup dibuat; purge dibatalkan.');
        }

        if (! hash_equals((string) $export->source_fingerprint, $current['source_fingerprint'])) {
            throw new RuntimeException('Isi record sumber berubah sejak backup dibuat; purge dibatalkan.');
        }

        if (in_array($export->scope, ['alerts', 'all'], true)) {
            foreach (['alert_ai_analyses', 'alert_triages', 'notification_logs'] as $entity) {
                $boundary = (int) data_get($export->snapshot_boundaries, $entity, 0);
                if ($this->entityQuery($export, $entity, false)->where('id', '>', $boundary)->exists()) {
                    throw new RuntimeException(
                        'Ada record terkait yang dibuat setelah backup; buat backup baru sebelum purge.',
                    );
                }
            }
        }
    }

    /**
     * Lock the exact source set while the purge transaction verifies and
     * deletes it. Locking parent alerts also blocks new FK children from being
     * attached between verification and parent deletion on InnoDB.
     */
    public function lockSourceSnapshot(DataExport $export): void
    {
        $chunkSize = (int) config('data_exports.chunk_size', 500);
        foreach ($this->includedEntities($export) as $entity) {
            $this->entityQuery($export, $entity)
                ->select('id')
                ->lockForUpdate()
                ->chunkById($chunkSize, static fn (): bool => true, 'id');
        }
    }

    /** @return list<string> */
    public function includedEntities(DataExport $export): array
    {
        return match ($export->scope) {
            'triages' => ['alerts', 'alert_triages'],
            'notif_logs' => ['alerts', 'notification_logs'],
            // Deleting an alert cascades all its children, so every dependency
            // must be in an archive that may later authorize that purge.
            'alerts', 'all' => ['alerts', 'alert_ai_analyses', 'alert_triages', 'notification_logs'],
            default => throw new RuntimeException('Scope ekspor tidak didukung.'),
        };
    }

    public function entityQuery(DataExport $export, string $entity, bool $applyBoundary = true): Builder
    {
        if (! array_key_exists($entity, self::COLUMNS)) {
            throw new RuntimeException("Entity ekspor {$entity} tidak dikenal.");
        }

        if ($entity === 'alerts') {
            $query = $this->alertSelectionQuery($export);
        } else {
            $query = DB::table($entity)->whereIn(
                'alert_id',
                $this->alertSelectionQuery($export)->select('id'),
            );
        }

        if ($applyBoundary) {
            $query->where('id', '<=', (int) data_get($export->snapshot_boundaries, $entity, 0));
        }

        return $query;
    }

    /** @return list<string> */
    public function columnsFor(string $entity): array
    {
        return self::COLUMNS[$entity] ?? throw new RuntimeException("Entity ekspor {$entity} tidak dikenal.");
    }

    /** @return array{record_counts: array<string, int>, source_fingerprint: string} */
    private function writeArchive(DataExport $export, string $path): array
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('File ekspor sementara tidak dapat ditulis.');
        }

        try {
            return $export->format === 'json'
                ? $this->writeJsonArchive($export, $handle)
                : $this->writeSqlArchive($export, $handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle
     * @return array{record_counts: array<string, int>, source_fingerprint: string}
     */
    private function writeJsonArchive(DataExport $export, $handle): array
    {
        $this->write($handle, "{\n");
        $this->write($handle, '  "schema": "siem-salabim-data-export/v2",'."\n");
        $this->write($handle, '  "backup_info": '.json_encode([
            'action' => 'backup',
            'export_uuid' => $export->uuid,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => $export->requested_by_name,
            'scope' => $export->scope,
            'period_label' => $export->period_label,
            'period_start' => $export->period_start?->toIso8601String(),
            'period_end' => $export->period_end?->toIso8601String(),
            'total_alerts' => $this->entityQuery($export, 'alerts')->count(),
            'total_ai_analyses' => in_array('alert_ai_analyses', $this->includedEntities($export), true)
                ? $this->entityQuery($export, 'alert_ai_analyses')->count()
                : 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).",\n");

        $hash = hash_init('sha256');
        $counts = [];
        $entities = $this->includedEntities($export);

        foreach ($entities as $entityIndex => $entity) {
            $this->write($handle, '  '.json_encode($entity, JSON_THROW_ON_ERROR).": [\n");
            $first = true;
            $count = 0;

            $this->eachRecord($export, $entity, function (array $record) use (
                $handle,
                $entity,
                &$first,
                &$count,
                $hash,
            ): void {
                $json = $this->canonicalJson($record);
                hash_update($hash, $entity."\0".$json."\n");
                if (! $first) {
                    $this->write($handle, ",\n");
                }
                $this->write($handle, '    '.$json);
                $first = false;
                $count++;
            });

            $counts[$entity] = $count;
            $this->write($handle, "\n  ]".($entityIndex < count($entities) - 1 ? ',' : '')."\n");
        }

        $this->write($handle, "}\n");

        return [
            'record_counts' => $counts,
            'source_fingerprint' => hash_final($hash),
        ];
    }

    /** @param resource $handle
     * @return array{record_counts: array<string, int>, source_fingerprint: string}
     */
    private function writeSqlArchive(DataExport $export, $handle): array
    {
        $driver = DB::connection()->getDriverName();
        $preamble = match ($driver) {
            'pgsql' => "BEGIN;\nSET LOCAL standard_conforming_strings = on;\n",
            'sqlite' => "BEGIN TRANSACTION;\n",
            'mysql', 'mariadb' => "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n",
            default => throw new RuntimeException('Format SQL tidak mendukung driver database ini.'),
        };
        $this->write($handle, "-- SIEM Salabim verified data export v3\n-- Database: {$driver}\n");
        $this->write($handle, "-- Restore into an existing matching schema with referenced users already present.\n");
        $this->write($handle, '-- Export UUID: '.$export->uuid."\n");
        $this->write($handle, '-- Generated by: '.json_encode($export->requested_by_name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->write($handle, '-- Period: '.$export->period_label."\n");
        $this->write($handle, $preamble."\n");

        $hash = hash_init('sha256');
        $counts = [];
        foreach ($this->includedEntities($export) as $entity) {
            $count = 0;
            $this->write($handle, "-- {$entity}\n");
            $this->eachRecord($export, $entity, function (array $record) use ($handle, $entity, $driver, &$count, $hash): void {
                $json = $this->canonicalJson($record);
                hash_update($hash, $entity."\0".$json."\n");
                $this->write($handle, $this->sqlInsert($entity, $record, $driver)."\n");
                $count++;
            });
            $counts[$entity] = $count;
            $this->write($handle, "\n");
        }

        if ($driver === 'pgsql') {
            // Explicit IDs do not advance PostgreSQL sequences. Never move a
            // sequence backwards when restoring into an existing database.
            foreach ($this->includedEntities($export) as $entity) {
                $this->write($handle, "SELECT setval(pg_get_serial_sequence('{$entity}', 'id'), GREATEST("
                    ."COALESCE((SELECT MAX(id) FROM \"{$entity}\"), 1), "
                    ."COALESCE(pg_sequence_last_value(pg_get_serial_sequence('{$entity}', 'id')::regclass), 1)), true);\n");
            }
        }
        $this->write($handle, "COMMIT;\n");
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        }
        $this->write($handle, "-- End of verified export\n");

        return [
            'record_counts' => $counts,
            'source_fingerprint' => hash_final($hash),
        ];
    }

    /** @return array{record_counts: array<string, int>, source_fingerprint: string} */
    private function fingerprintSource(DataExport $export): array
    {
        $hash = hash_init('sha256');
        $counts = [];
        foreach ($this->includedEntities($export) as $entity) {
            $count = 0;
            $this->eachRecord($export, $entity, function (array $record) use ($entity, &$count, $hash): void {
                hash_update($hash, $entity."\0".$this->canonicalJson($record)."\n");
                $count++;
            });
            $counts[$entity] = $count;
        }

        return [
            'record_counts' => $counts,
            'source_fingerprint' => hash_final($hash),
        ];
    }

    /** @param callable(array<string, mixed>): void $callback */
    private function eachRecord(DataExport $export, string $entity, callable $callback): void
    {
        $columns = $this->columnsFor($entity);
        $chunkSize = (int) config('data_exports.chunk_size', 500);
        foreach ($this->entityQuery($export, $entity)
            ->select($columns)
            ->lazyById($chunkSize, 'id') as $row) {
            $attributes = (array) $row;
            $record = [];
            foreach ($columns as $column) {
                $record[$column] = $attributes[$column] ?? null;
            }
            $callback($record);
        }
    }

    /** @return array<string, int> */
    private function captureBoundaries(DataExport $export): array
    {
        $boundaries = [];
        $alertMax = (int) ($this->alertSelectionQuery($export)->max('id') ?? 0);
        $boundaries['alerts'] = $alertMax;

        foreach ($this->includedEntities($export) as $entity) {
            if ($entity === 'alerts') {
                continue;
            }
            $boundaries[$entity] = (int) ($this->entityQuery(
                $export->forceFill(['snapshot_boundaries' => ['alerts' => $alertMax]]),
                $entity,
                false,
            )->max('id') ?? 0);
        }

        return $boundaries;
    }

    private function alertSelectionQuery(DataExport $export): Builder
    {
        return DB::table('alerts')
            ->when($export->period_start, fn (Builder $query, $from) => $query->where('first_seen_at', '>=', $from))
            ->when(
                $export->period_end,
                fn (Builder $query, $toExclusive) => $query->where('first_seen_at', '<', $toExclusive),
            )
            ->when(
                data_get($export->snapshot_boundaries, 'alerts'),
                fn (Builder $query, $maxId) => $query->where('id', '<=', (int) $maxId),
            );
    }

    /** @param array<string, mixed> $record */
    private function canonicalJson(array $record): string
    {
        return json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, int> */
    private function normalizeRecordCounts(mixed $counts): array
    {
        if (! is_array($counts)) {
            throw new RuntimeException('Jumlah record ekspor tidak valid.');
        }

        $normalized = [];
        foreach ($counts as $entity => $count) {
            if (! is_string($entity) || ! array_key_exists($entity, self::COLUMNS)) {
                throw new RuntimeException('Entity pada jumlah record ekspor tidak valid.');
            }

            if (is_string($count)) {
                if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $count) !== 1) {
                    throw new RuntimeException("Jumlah record {$entity} tidak valid.");
                }

                $integerCount = filter_var($count, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0],
                ]);
                if ($integerCount === false) {
                    throw new RuntimeException("Jumlah record {$entity} tidak valid.");
                }

                $count = $integerCount;
            }

            if (! is_int($count) || $count < 0) {
                throw new RuntimeException("Jumlah record {$entity} tidak valid.");
            }

            $normalized[$entity] = $count;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @param array<string, mixed> $record */
    private function sqlInsert(string $table, array $record, string $driver): string
    {
        $quote = in_array($driver, ['mysql', 'mariadb'], true) ? '`' : '"';
        $columns = array_keys($record);
        $names = $quote.implode($quote.','.$quote, $columns).$quote;
        $values = array_map(fn (mixed $value): string => $this->sqlLiteral($value, $driver), array_values($record));
        $insert = match ($driver) {
            'pgsql' => 'INSERT INTO',
            'sqlite' => 'INSERT OR IGNORE INTO',
            default => 'INSERT IGNORE INTO',
        };
        $conflict = $driver === 'pgsql' ? ' ON CONFLICT DO NOTHING' : '';

        return "{$insert} {$quote}{$table}{$quote} ({$names}) VALUES (".implode(',', $values).')'.$conflict.';';
    }

    private function sqlLiteral(mixed $value, string $driver): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return match ($driver) {
            // The PostgreSQL archive pins standard_conforming_strings so
            // backslashes remain literal; quote doubling prevents injection.
            'pgsql' => "'".str_replace("'", "''", (string) $value)."'",
            'sqlite' => "CAST(X'".bin2hex((string) $value)."' AS TEXT)",
            default => "_utf8mb4 X'".bin2hex((string) $value)."'",
        };
    }

    /** @param resource $handle */
    private function write($handle, string $contents): void
    {
        $remaining = $contents;
        while ($remaining !== '') {
            $written = fwrite($handle, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Penulisan file ekspor terhenti.');
            }
            $remaining = (string) substr($remaining, $written);
        }
    }

    private function hashStoredFile(string $diskName, string $path): string
    {
        $stream = Storage::disk($diskName)->readStream($path);
        if ($stream === null || $stream === false) {
            throw new RuntimeException('File ekspor pada storage tidak dapat dibaca.');
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function createTemporaryFile(): string
    {
        $directory = trim((string) config(
            'data_exports.temp_directory',
            storage_path('app/private/siem-export-tmp'),
        ));

        if ($directory === '') {
            throw new RuntimeException('Direktori sementara ekspor belum dikonfigurasi.');
        }

        if ($this->pathIsWithin($directory, storage_path('app/public')) || $this->pathIsWithin($directory, public_path())) {
            throw new RuntimeException('Direktori sementara ekspor tidak boleh berada di direktori publik.');
        }

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Direktori sementara ekspor tidak dapat dibuat.');
        }

        // Resolve again after creation so relative paths and symlinks cannot
        // bypass the public-directory check above.
        if ($this->pathIsWithin($directory, storage_path('app/public')) || $this->pathIsWithin($directory, public_path())) {
            throw new RuntimeException('Direktori sementara ekspor tidak boleh berada di direktori publik.');
        }

        if (! is_writable($directory)) {
            throw new RuntimeException('Direktori sementara ekspor tidak dapat ditulis.');
        }

        $temporaryPath = tempnam($directory, 'siem-export-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Tidak dapat membuat file sementara untuk ekspor.');
        }

        try {
            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Permission file sementara ekspor tidak dapat diamankan.');
            }
        } catch (\Throwable $exception) {
            $this->deleteTemporaryFile($temporaryPath);
            throw $exception;
        }

        return $temporaryPath;
    }

    private function deleteTemporaryFile(string $temporaryPath): void
    {
        if ($temporaryPath === '' || ! file_exists($temporaryPath)) {
            return;
        }

        try {
            if (! unlink($temporaryPath)) {
                Log::error('[Data Export] Temporary file could not be deleted', [
                    'path' => $temporaryPath,
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error('[Data Export] Temporary file deletion failed', [
                'path' => $temporaryPath,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function pathIsWithin(string $path, string $parent): bool
    {
        $normalizedPath = $this->normalizePath($path);
        $normalizedParent = $this->normalizePath($parent);

        return $normalizedPath === $normalizedParent
            || str_starts_with($normalizedPath, $normalizedParent.'/');
    }

    private function normalizePath(string $path): string
    {
        if (! str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = base_path($path);
        }

        $resolved = realpath($path);
        $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
        $normalized = rtrim($normalized, '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    /**
     * @param  array{record_counts: array<string, int>, source_fingerprint: string}  $snapshot
     * @return array<string, mixed>
     */
    private function buildManifest(
        DataExport $export,
        string $storagePath,
        string $checksum,
        int $size,
        array $snapshot,
    ): array {
        return [
            'schema' => 'siem-salabim-export-manifest/v1',
            'export_uuid' => $export->uuid,
            'created_at' => now()->toIso8601String(),
            'created_by' => [
                'user_id' => $export->requested_by_user_id,
                'name' => $export->requested_by_name,
            ],
            'selection' => [
                'scope' => $export->scope,
                'period_label' => $export->period_label,
                'period_start' => $export->period_start?->toIso8601String(),
                'period_end' => $export->period_end?->toIso8601String(),
                'snapshot_at' => $export->source_snapshot_at?->toIso8601String(),
                'boundaries' => $export->snapshot_boundaries,
            ],
            'source' => [
                'record_counts' => $snapshot['record_counts'],
                'fingerprint' => $snapshot['source_fingerprint'],
            ],
            'archive' => [
                'disk' => $export->storage_disk,
                'path' => $storagePath,
                'filename' => $export->filename,
                'format' => $export->format,
                'database_driver' => DB::connection()->getDriverName(),
                'bytes' => $size,
                'sha256' => $checksum,
            ],
        ];
    }

    private function archivePath(DataExport $export): string
    {
        return config('data_exports.directory', 'siem-exports')."/{$export->uuid}/{$export->filename}";
    }

    private function manifestPath(DataExport $export): string
    {
        return config('data_exports.directory', 'siem-exports')."/{$export->uuid}/manifest.json";
    }
}
