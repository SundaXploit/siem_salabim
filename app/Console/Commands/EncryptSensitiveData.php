<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\AlertAiAnalysis;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\DashboardInsight;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Services\EncryptedSearchIndex;
use App\Services\Installation\EnvironmentFileWriter;
use App\Services\SensitiveDataCipher;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class EncryptSensitiveData extends Command
{
    private $migrationLock;

    protected $signature = 'siem:encrypt-data
        {--check : Authenticate stored values and report pending legacy fields without changing data}
        {--rotate : Re-encrypt every non-null value with the current APP_KEY and cipher}
        {--allow-legacy : Read legacy plaintext only during this explicit repair command}
        {--chunk=100 : Rows locked per transaction, between 1 and 1000}';

    protected $description = 'Encrypt legacy sensitive data and rebuild keyed search indexes in resumable batches';

    private const FIELDS = [
        Alert::class => ['raw_data', 'src_ip', 'dst_ip'],
        AlertAiAnalysis::class => ['summary', 'supporting_indicators', 'legitimate_indicators', 'recommended_checks', 'limitations'],
        NotificationLog::class => ['message', 'delivery_details'],
        AlertTriage::class => ['reason'],
        DashboardInsight::class => ['summary', 'source_snapshot'],
        DataExport::class => ['archive_password'],
    ];

    public function handle(EncryptedSearchIndex $index, EnvironmentFileWriter $environment): int
    {
        $this->migrationLock = fopen(config('data_security.migration_lock_path', storage_path('framework/siem-data-encryption.lock')), 'c+');
        if (! $this->migrationLock || ! flock($this->migrationLock, LOCK_EX | LOCK_NB)) {
            if (is_resource($this->migrationLock)) {
                fclose($this->migrationLock);
            }
            $this->error('Migrasi/pemeriksaan enkripsi lain masih berjalan.');

            return self::FAILURE;
        }
        $allowLegacy = config('data_security.allow_legacy_plaintext');
        try {
            if ($this->option('allow-legacy')) {
                config(['data_security.allow_legacy_plaintext' => true]);
            }

            return $this->runMigration($index, $environment);
        } finally {
            config(['data_security.allow_legacy_plaintext' => $allowLegacy]);
            flock($this->migrationLock, LOCK_UN);
            fclose($this->migrationLock);
        }
    }

    private function runMigration(EncryptedSearchIndex $index, EnvironmentFileWriter $environment): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if (! $chunk || $chunk < 1 || $chunk > 1000 || ($this->option('check') && $this->option('rotate'))) {
            $this->error('Gunakan --chunk=1..1000 dan pilih --check atau --rotate.');

            return self::INVALID;
        }
        if (strtoupper(config('app.cipher')) !== 'AES-256-GCM' || ! $index->available()) {
            $this->error('Gunakan APP_CIPHER=AES-256-GCM dan jalankan migrasi database terlebih dahulu.');

            return self::FAILURE;
        }
        if (! $this->option('check') && blank(config('data_security.blind_index_key'))) {
            // Never retain an old encryption key as the permanent search key.
            $key = $environment->value('SIEM_BLIND_INDEX_KEY') ?: 'base64:'.base64_encode(random_bytes(32));
            $environment->updatePreservingAppKey(['SIEM_BLIND_INDEX_KEY' => $key]);
            config(['data_security.blind_index_key' => $key]);
            $this->info('Kunci indeks independen disiapkan; simpan juga dalam pengelolaan secret/backup kunci.');
        }

        $pending = 0;
        try {
            foreach (self::FIELDS as $class => $fields) {
                $prototype = new $class;
                $table = $prototype->getTable();
                $counts = ['rows' => 0, 'pending_fields' => 0, 'rewritten_fields' => 0, 'index_mismatches' => 0];
                DB::table($table)->select('id')->orderBy('id')->chunkById($chunk, function ($ids) use ($class, $fields, $table, $index, &$counts): void {
                    DB::transaction(function () use ($ids, $class, $fields, $table, $index, &$counts): void {
                        $query = DB::table($table)->whereIn('id', $ids->pluck('id'))->orderBy('id');
                        if (! $this->option('check')) {
                            $query->lockForUpdate();
                        }
                        foreach ($query->get() as $row) {
                            $model = (new $class)->newFromBuilder((array) $row);
                            $updates = [];
                            $plain = [];
                            foreach ($fields as $field) {
                                if ($row->{$field} === null) {
                                    continue;
                                }
                                $current = $this->isGcm($row->{$field});
                                $counts['pending_fields'] += $current ? 0 : 1;
                                // Reading authenticates AEAD/MAC even when no rewrite is needed.
                                $value = $model->{$field};
                                $plain[$field] = $value;
                                if (! $this->option('check') && (! $current || $this->option('rotate'))) {
                                    $model->{$field} = $value;
                                    $updates[$field] = $model->getAttributes()[$field] ?? null;
                                    $counts['rewritten_fields']++;
                                }
                                if ($class === Alert::class && in_array($field, ['src_ip', 'dst_ip'], true)) {
                                    $ip = EncryptedSearchIndex::ip($value);
                                    $hash = $ip === null ? null : EncryptedSearchIndex::hash('ip', $ip);
                                    $counts['index_mismatches'] += ($row->{$field.'_hash'} ?? null) === $hash ? 0 : 1;
                                    if (($row->{$field.'_hash'} ?? null) !== $hash) {
                                        $updates[$field.'_hash'] = $hash;
                                    }
                                }
                            }
                            if ($class === Alert::class) {
                                $projection = Alert::mitreMetadata($plain['raw_data'] ?? null);
                                if (json_decode($row->mitre_metadata ?? 'null', true) != $projection) {
                                    $counts['pending_fields']++;
                                    $updates['mitre_metadata'] = json_encode($projection, JSON_THROW_ON_ERROR);
                                }
                            }
                            $entity = match ($class) {
                                Alert::class => 'alert', NotificationLog::class => 'notification', AlertTriage::class => 'triage', default => null,
                            };
                            if ($entity) {
                                $values = match ($entity) {
                                    'alert' => [$plain['raw_data'] ?? null, $plain['src_ip'] ?? null, $plain['dst_ip'] ?? null],
                                    'notification' => [$plain['message'] ?? null], 'triage' => [$plain['reason'] ?? null],
                                };
                                if ($this->option('check') && ! $index->matches($entity, $row->id, $class === Alert::class ? $row->id : $row->alert_id, $values)) {
                                    $counts['index_mismatches']++;
                                }
                            }
                            if (! $this->option('check')) {
                                if ($updates !== []) {
                                    DB::table($table)->where('id', $row->id)->update($updates);
                                }
                                if ($entity) {
                                    $index->replace($entity, $row->id, $class === Alert::class ? $row->id : $row->alert_id, $values);
                                }
                            }
                            $counts['rows']++;
                        }
                    }, 3);
                });
                $pending += $counts['pending_fields'] + $counts['index_mismatches'];
                $this->line($table.': '.json_encode($counts, JSON_THROW_ON_ERROR));
            }
            $secrets = Configuration::whereIn('key', ['opensearch_password', 'telegram_bot_token', 'ai_api_key'])->get();
            $secretPending = 0;
            foreach ($secrets as $secret) {
                if ($secret->value === null || $secret->value === '') {
                    continue;
                }
                $secretPending += $this->isGcm($secret->value) ? 0 : 1;
                $value = Configuration::get($secret->key);
                if (! $this->option('check') && (! $this->isGcm($secret->value) || $this->option('rotate'))) {
                    Configuration::set($secret->key, $value);
                }
            }
            $pending += $secretPending;
            $this->line('configurations: '.json_encode(['secret_rows' => $secrets->count(), 'pending_fields' => $secretPending]));
            $pending += $this->migrateBulkCache();
        } catch (Throwable $exception) {
            // A database/crypto exception can contain payloads or URLs. IDs and
            // earlier committed batches remain safe to resume without printing it.
            $this->error('Migrasi/pemeriksaan berhenti ('.$exception::class.'). Batch sebelumnya tersimpan. Periksa kunci dan skema; jangan menghapus APP_PREVIOUS_KEYS atau mengaktifkan fallback untuk menyembunyikan ciphertext yang rusak.');

            return self::FAILURE;
        }
        $this->info($this->option('check') ? "Pemeriksaan selesai; entri yang perlu dimigrasikan/diperbaiki: {$pending}." : 'Enkripsi dan indeks selesai. Jalankan kembali dengan --check tanpa --allow-legacy untuk memverifikasi pembacaan ketat.');

        return $this->option('check') && $pending > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isGcm(string $stored): bool
    {
        $json = json_decode($stored, true);
        $value = is_string($json) ? $json : $stored;
        if (str_starts_with($value, SensitiveDataCipher::PREFIX)) {
            $value = substr($value, strlen(SensitiveDataCipher::PREFIX));
        }
        $envelope = json_decode(base64_decode($value, true) ?: '', true);

        return is_array($envelope) && is_string($envelope['tag'] ?? null) && strlen(base64_decode($envelope['tag'], true) ?: '') === 16;
    }

    private function migrateBulkCache(): int
    {
        $store = Cache::getStore();
        if (! $store instanceof DatabaseStore) {
            return 0;
        }
        $prefix = $store->getPrefix();
        $pending = 0;
        DB::connection(config('cache.stores.database.connection'))->table(config('cache.stores.database.table', 'cache'))
            ->where('key', 'like', $prefix.'bulk-alert-ai:v1:%')->orderBy('key')->chunk(100, function ($rows) use ($prefix, &$pending): void {
                foreach ($rows as $row) {
                    if (! str_starts_with($row->key, $prefix.'bulk-alert-ai:v1:')) {
                        continue;
                    }
                    $key = substr($row->key, strlen($prefix));
                    if ($row->expiration <= time()) {
                        $pending++;
                        if (! $this->option('check')) {
                            Cache::forget($key);
                        }

                        continue;
                    }
                    $value = Cache::get($key);
                    if ($value === null) {
                        continue;
                    }
                    if (is_array($value)) {
                        $pending++;
                        if (! $this->option('check')) {
                            Cache::put($key, SensitiveDataCipher::encryptJson($value), max(1, $row->expiration - time()));
                        }
                    } else {
                        $pending += $this->isGcm($value) ? 0 : 1;
                        SensitiveDataCipher::decryptJson($value);
                        if ($this->option('rotate') && ! $this->option('check')) {
                            Cache::put($key, SensitiveDataCipher::encryptJson(SensitiveDataCipher::decryptJson($value)), max(1, $row->expiration - time()));
                        }
                    }
                }
            });

        return $pending;
    }
}
