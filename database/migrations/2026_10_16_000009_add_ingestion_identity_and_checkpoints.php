<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertLegacyIdsFit(512, 'Migrasi ingestion dibatalkan');

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('source_cluster', 64)->default('legacy')->after('wazuh_alert_id');
            $table->string('source_index', 255)->default('legacy')->after('source_cluster');
            $table->string('source_id', 512)->nullable()->after('source_index');
            $table->char('source_identity_hash', 64)->nullable()->after('source_id');
        });

        // Compute the digest in PHP so identity always uses the exact UTF-8
        // bytes and never inherits a database's case-insensitive collation.
        DB::table('alerts')
            ->select(['id', 'wazuh_alert_id'])
            ->orderBy('id')
            ->chunkById(500, function ($alerts): void {
                foreach ($alerts as $alert) {
                    $sourceId = (string) $alert->wazuh_alert_id;
                    DB::table('alerts')->where('id', $alert->id)->update([
                        'source_id' => $sourceId,
                        'source_identity_hash' => $this->identityHash('legacy', 'legacy', $sourceId),
                    ]);
                }
            });

        Schema::table('alerts', function (Blueprint $table) {
            $table->unique('source_identity_hash', 'alerts_source_identity_hash_unique');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique('alerts_wazuh_alert_id_unique');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->string('wazuh_alert_id', 512)->comment('_id dari OpenSearch')->change();
            $table->string('source_id', 512)->nullable(false)->change();
            $table->char('source_identity_hash', 64)->nullable(false)->change();
        });

        Schema::create('ingestion_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->char('stream_key', 64)->unique();
            $table->string('source_cluster', 64);
            $table->string('source_index', 255);
            $table->unsignedTinyInteger('min_level');
            $table->dateTime('cursor_at', 6);
            $table->dateTime('last_completed_at', 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Run every irreversible compatibility check before the first DDL.
        // The GROUP BY uses the column's actual collation, matching the unique
        // key that will be restored on this database engine.
        $this->assertLegacyIdsFit(100, 'Rollback ingestion identity dibatalkan');
        $hasDuplicateLegacyId = DB::table('alerts')
            ->select('wazuh_alert_id')
            ->groupBy('wazuh_alert_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateLegacyId) {
            throw new RuntimeException(
                'Rollback ingestion identity dibatalkan: terdapat _id Wazuh yang sama pada source index berbeda.',
            );
        }

        // Shrink before restoring the legacy index. Indexing VARCHAR(512)
        // can exceed key-length limits on older MySQL/MariaDB installations,
        // while the hash key still protects uniqueness during this DDL.
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('wazuh_alert_id', 100)->comment('_id dari OpenSearch')->change();
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->unique('wazuh_alert_id');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique('alerts_source_identity_hash_unique');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['source_cluster', 'source_index', 'source_id', 'source_identity_hash']);
        });

        // Keep checkpoints until the alert schema is fully restored. If an
        // earlier DDL fails, the operational watermark remains available.
        Schema::dropIfExists('ingestion_checkpoints');
    }

    private function assertLegacyIdsFit(int $maximumBytes, string $prefix): void
    {
        DB::table('alerts')
            ->select(['id', 'wazuh_alert_id'])
            ->orderBy('id')
            ->chunkById(500, function ($alerts) use ($maximumBytes, $prefix): void {
                foreach ($alerts as $alert) {
                    $sourceId = (string) $alert->wazuh_alert_id;
                    if ($sourceId === '' || strlen($sourceId) > $maximumBytes) {
                        throw new RuntimeException(
                            "{$prefix}: _id Wazuh pada alert {$alert->id} harus berisi 1-{$maximumBytes} byte.",
                        );
                    }
                }
            });
    }

    private function identityHash(string $cluster, string $index, string $sourceId): string
    {
        $payload = pack('N', strlen($cluster)).$cluster
            .pack('N', strlen($index)).$index
            .pack('N', strlen($sourceId)).$sourceId;

        return hash('sha256', $payload);
    }
};
