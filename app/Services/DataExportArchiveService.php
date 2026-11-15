<?php

namespace App\Services;

use App\Models\DataExport;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class DataExportArchiveService
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'alerts' => [
            'id', 'wazuh_alert_id', 'source_cluster', 'source_index', 'source_id', 'source_identity_hash',
            'rule_description', 'src_ip', 'dst_ip', 'src_ip_hash', 'dst_ip_hash', 'agent_name', 'rule_id',
            'rule_level', 'status', 'acknowledged_by_user_id', 'acknowledged_at', 'agent_scope_key', 'first_seen_at', 'raw_data', 'created_at',
            'updated_at', 'mitre_metadata',
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
        'manual_notification_operations' => [
            'id', 'alert_id', 'user_id', 'payload_hash', 'created_at',
        ],
        'notification_logs' => [
            'id', 'alert_id', 'user_id', 'chat_id', 'message',
            'response_status', 'sent_at', 'automatic_key', 'delivery_state', 'manual_operation_id', 'delivery_details',
        ],
        'encrypted_search_tokens' => ['id', 'alert_id', 'entity', 'record_id', 'token_hash'],
    ];

    /**
     * Build an archive with bounded memory, upload it to private storage, and
     * verify the durable object before marking the export as usable.
     *
     * @return array<string, mixed>
     */
    public function generate(DataExport $export): array
    {
        $this->assertZipAvailable();
        $this->assertPrivateStorageDisk($export->storage_disk);
        $export->filename = pathinfo($export->filename, PATHINFO_FILENAME).'.zip';
        $storagePath = $this->archivePath($export);
        $manifestPath = $this->manifestPath($export);
        $disk = Storage::disk($export->storage_disk);
        $temporaryPath = $this->createTemporaryFile();
        $compressedPath = null;

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

            $compressedPath = $this->createTemporaryFile();
            $this->compressZip($temporaryPath, $compressedPath, 'data.'.$export->format);
            $localChecksum = hash_file('sha256', $compressedPath);
            $localSize = filesize($compressedPath);
            if ($localChecksum === false || $localSize === false) {
                throw new RuntimeException('Checksum file ekspor tidak dapat dihitung.');
            }

            $archiveStream = fopen($compressedPath, 'rb');
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
                'archive_password' => null,
            ])->save();

            return $manifest;
        } catch (\Throwable $exception) {
            $disk->delete([$storagePath, $manifestPath]);
            throw $exception;
        } finally {
            $this->deleteTemporaryFile($temporaryPath);
            if ($compressedPath !== null) {
                $this->deleteTemporaryFile($compressedPath);
            }
        }
    }

    public function assertZipAvailable(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP Zip tidak tersedia pada server.');
        }
    }

    private function compressZip(string $source, string $destination, string $entry): void
    {
        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Arsip ZIP tidak dapat dibuat.');
        }
        try {
            if (! $zip->addFile($source, $entry)
                || ! $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE)) {
                throw new RuntimeException('Kompresi ZIP gagal.');
            }
        } finally {
            $closed = $zip->close();
        }
        if (! $closed) {
            throw new RuntimeException('Arsip ZIP gagal disimpan.');
        }

        // Read back the payload with bounded memory before accepting
        // the archive as a backup which can authorize a later purge.
        if ($zip->open($destination, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Arsip ZIP tidak dapat diverifikasi.');
        }
        try {
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                throw new RuntimeException('Isi arsip ZIP tidak dapat dibaca.');
            }
            try {
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                $checksum = hash_final($hash);
            } finally {
                fclose($stream);
            }
            if ($bytes !== filesize($source) || ! hash_equals((string) hash_file('sha256', $source), $checksum)) {
                throw new RuntimeException('Isi arsip ZIP tidak cocok dengan data sumber.');
            }
        } finally {
            $zip->close();
        }
    }

    /** Repackage an older encrypted backup without changing its stored snapshot. */
    public function prepareStandardZipDownload(DataExport $export): string
    {
        $sourcePath = $this->createTemporaryFile();
        $payloadPath = $this->createTemporaryFile();
        $downloadPath = $this->createTemporaryFile();
        $zip = new ZipArchive;
        try {
            $source = Storage::disk($export->storage_disk)->readStream($export->storage_path);
            $destination = fopen($sourcePath, 'wb');
            try {
                if ($source === false || $destination === false || stream_copy_to_stream($source, $destination) === false) {
                    throw new RuntimeException('Arsip lama tidak dapat dibaca.');
                }
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
                if (is_resource($destination)) {
                    fclose($destination);
                }
            }
            if (! hash_equals($export->checksum, (string) hash_file('sha256', $sourcePath))
                || $zip->open($sourcePath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Integritas arsip lama tidak valid.');
            }
            try {
                $entry = 'data.'.$export->format;
                $stat = $zip->statName($entry);
                $zip->setPassword($export->archive_password);
                $payload = $zip->getStream($entry);
                $destination = fopen($payloadPath, 'wb');
                try {
                    if ($stat === false || $payload === false || $destination === false
                        || stream_copy_to_stream($payload, $destination) !== $stat['size']) {
                        throw new RuntimeException('Isi arsip lama tidak dapat dibaca.');
                    }
                } finally {
                    if (is_resource($payload)) {
                        fclose($payload);
                    }
                    if (is_resource($destination)) {
                        fclose($destination);
                    }
                }
                if (hash_file('crc32b', $payloadPath) !== sprintf('%08x', $stat['crc'])) {
                    throw new RuntimeException('Checksum isi arsip lama tidak cocok.');
                }
            } finally {
                $zip->close();
            }
            $this->compressZip($payloadPath, $downloadPath, $entry);

            return $downloadPath;
        } catch (\Throwable $exception) {
            $this->deleteTemporaryFile($downloadPath);
            throw $exception;
        } finally {
            $this->deleteTemporaryFile($sourcePath);
            $this->deleteTemporaryFile($payloadPath);
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

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if ($connection->transactionLevel() !== 0) {
                throw new RuntimeException('Ekspor konsisten tidak dapat dimulai di dalam transaksi lain.');
            }

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
            foreach (['alert_ai_analyses', 'alert_triages', 'manual_notification_operations', 'notification_logs', 'encrypted_search_tokens'] as $entity) {
                $boundary = data_get($export->snapshot_boundaries, $entity);
                $newer = $this->entityQuery($export, $entity, false);
                if ($boundary !== null) {
                    $newer->where('id', '>', $boundary);
                }
                if ($newer->exists()) {
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
            'triages' => ['alerts', 'alert_triages', 'encrypted_search_tokens'],
            'notif_logs' => ['alerts', 'manual_notification_operations', 'notification_logs', 'encrypted_search_tokens'],
            // Deleting an alert cascades all its children, so every dependency
            // must be in an archive that may later authorize that purge.
            'alerts', 'all' => ['alerts', 'alert_ai_analyses', 'alert_triages', 'manual_notification_operations', 'notification_logs', 'encrypted_search_tokens'],
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
            $boundary = data_get($export->snapshot_boundaries, $entity);
            $boundary === null ? $query->whereRaw('1 = 0') : $query->where('id', '<=', $boundary);
        }

        if ($entity === 'encrypted_search_tokens' && ! in_array($export->scope, ['alerts', 'all'], true)) {
            $allowed = $export->scope === 'triages' ? ['alert', 'triage'] : ['alert', 'notification'];
            $query->whereIn('entity', $allowed);
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
            'database' => $this->databaseMetadata(),
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
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Ekspor SQL belum mendukung driver {$driver}. Gunakan format JSON.");
        }
        $this->write($handle, "-- SIEM Salabim verified data export v3\n");
        $this->write($handle, "-- Database driver: {$driver}\n");
        $this->write($handle, '-- Schema migrations: '.json_encode($this->databaseMetadata()['schema_migrations'], JSON_THROW_ON_ERROR)."\n");
        $this->write($handle, '-- Export UUID: '.$export->uuid."\n");
        $this->write($handle, '-- Generated by: '.json_encode($export->requested_by_name, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->write($handle, '-- Period: '.json_encode($export->period_label, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->write($handle, "-- Restore into the same database engine and matching application schema.\n");
        $this->write($handle, "-- Referenced users must already exist with their original IDs. Stop writers during restore.\n");
        $this->write($handle, "-- Existing conflicting IDs are errors; this file does not overwrite or silently skip data.\n");
        $this->write($handle, match ($driver) {
            'pgsql' => "BEGIN;\nSET LOCAL client_encoding = 'UTF8';\nSET LOCAL standard_conforming_strings = on;\n\n",
            'sqlite' => "PRAGMA foreign_keys = ON;\nBEGIN TRANSACTION;\n\n",
            default => "SET NAMES utf8mb4;\nSTART TRANSACTION;\n\n",
        });
        if ($driver === 'pgsql') {
            $tables = array_map(fn ($entity) => DB::connection()->getQueryGrammar()->wrapTable($entity), $this->includedEntities($export));
            $this->write($handle, 'LOCK TABLE '.implode(', ', $tables)." IN SHARE ROW EXCLUSIVE MODE;\n\n");
        }

        $hash = hash_init('sha256');
        $counts = [];
        foreach ($this->includedEntities($export) as $entity) {
            $count = 0;
            $this->write($handle, "-- {$entity}\n");
            $this->eachRecord($export, $entity, function (array $record) use ($handle, $entity, &$count, $hash, $driver): void {
                $json = $this->canonicalJson($record);
                hash_update($hash, $entity."\0".$json."\n");
                $this->write($handle, $this->sqlInsert($entity, $record, $driver)."\n");
                $count++;
            });
            $counts[$entity] = $count;
            $this->write($handle, "\n");
        }

        if ($driver === 'pgsql') {
            foreach ($counts as $entity => $count) {
                if ($entity === 'manual_notification_operations' || $count === 0) {
                    continue;
                }
                $table = DB::connection()->getQueryGrammar()->wrapTable($entity);
                $sequence = 'pg_get_serial_sequence('.$this->sqlLiteral($table, $driver).", 'id')";
                // Reserve nextval first so an older backup never rewinds a sequence.
                $this->write($handle, "SELECT setval({$sequence}, GREATEST(COALESCE(MAX(\"id\"), 1), nextval({$sequence})), true) FROM {$table};\n");
            }
        }
        $this->write($handle, "COMMIT;\n-- End of verified export\n");

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

    /** @return array<string, int|string|null> */
    private function captureBoundaries(DataExport $export): array
    {
        $boundaries = [];
        $alertMax = (int) ($this->alertSelectionQuery($export)->max('id') ?? 0);
        $boundaries['alerts'] = $alertMax;

        foreach ($this->includedEntities($export) as $entity) {
            if ($entity === 'alerts') {
                continue;
            }
            $boundaries[$entity] = $this->entityQuery(
                $export->forceFill(['snapshot_boundaries' => ['alerts' => $alertMax]]),
                $entity,
                false,
            )->orderByDesc('id')->value('id');
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
        $grammar = DB::connection()->getQueryGrammar();
        $names = implode(',', array_map(fn ($column) => $grammar->wrap($column), array_keys($record)));
        $values = array_map(fn (mixed $value): string => $this->sqlLiteral($value, $driver), array_values($record));

        return 'INSERT INTO '.$grammar->wrapTable($table)." ({$names}) VALUES (".implode(',', $values).');';
    }

    private function sqlLiteral(mixed $value, string $driver): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $driver === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        }
        if ($driver === 'pgsql') {
            if (str_contains((string) $value, "\0") || ! mb_check_encoding((string) $value, 'UTF-8')) {
                throw new RuntimeException('PostgreSQL memerlukan teks UTF-8 tanpa null byte. Ekspor dibatalkan.');
            }

            return "'".str_replace("'", "''", (string) $value)."'";
        }
        if ($driver === 'sqlite') {
            return "CAST(X'".bin2hex((string) $value)."' AS TEXT)";
        }

        return "_utf8mb4 X'".bin2hex((string) $value)."'";
    }

    private function databaseMetadata(): array
    {
        return [
            'driver' => DB::connection()->getDriverName(),
            'schema_migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
            'contents' => 'operational_data_only',
            'requires_existing_users' => true,
            'encryption' => [
                'cipher' => config('app.cipher'),
                'application_key_fingerprint' => substr(hash('sha256', app('encrypter')->getKey()), 0, 16),
                'read_key_fingerprints' => array_map(fn ($key) => substr(hash('sha256', $key), 0, 16), app('encrypter')->getAllKeys()),
                'blind_index_key_fingerprint' => substr(hash('sha256', EncryptedSearchIndex::key()), 0, 16),
                'requires_original_or_previous_application_keys' => true,
                'requires_original_blind_index_key' => true,
            ],
        ];
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
                'database' => $this->databaseMetadata(),
                'record_counts' => $snapshot['record_counts'],
                'fingerprint' => $snapshot['source_fingerprint'],
            ],
            'archive' => [
                'disk' => $export->storage_disk,
                'path' => $storagePath,
                'filename' => $export->filename,
                'format' => $export->format,
                'container' => 'zip',
                'encryption' => 'none',
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
