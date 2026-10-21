<?php

namespace App\Services;

use App\Models\DataExport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DataExportPurgeService
{
    public function __construct(private readonly DataExportArchiveService $archives) {}

    /** @return array<string, int> */
    public function purge(DataExport $export): array
    {
        $export->refresh();
        $this->assertWithinRowLimit($export);
        $this->archives->verifyArchive($export);

        return DB::transaction(function () use ($export): array {
            $lockedExport = DataExport::query()
                ->whereKey($export->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedExport->status !== 'verified' || $lockedExport->purge_status !== 'running') {
                throw new RuntimeException('Status ekspor tidak lagi mengizinkan purge.');
            }

            $this->assertWithinRowLimit($lockedExport);
            $this->archives->lockSourceSnapshot($lockedExport);
            $this->archives->verifySourceSnapshot($lockedExport);

            $counts = [];
            if (in_array($lockedExport->scope, ['alerts', 'all'], true)) {
                // Explicit child deletion lets us compare every affected row
                // with the verified manifest before deleting parent alerts.
                foreach (['alert_ai_analyses', 'alert_triages', 'notification_logs'] as $entity) {
                    $counts[$entity] = $this->archives->entityQuery($lockedExport, $entity)->delete();
                }
                $counts['alerts'] = $this->archives->entityQuery($lockedExport, 'alerts')->delete();
            } elseif ($lockedExport->scope === 'triages') {
                $counts['alert_triages'] = $this->archives->entityQuery($lockedExport, 'alert_triages')->delete();
            } elseif ($lockedExport->scope === 'notif_logs') {
                $counts['notification_logs'] = $this->archives->entityQuery($lockedExport, 'notification_logs')->delete();
            } else {
                throw new RuntimeException('Scope purge tidak didukung.');
            }

            foreach ($counts as $entity => $deleted) {
                $expected = (int) data_get($lockedExport->record_counts, $entity, 0);
                if ($deleted !== $expected) {
                    throw new RuntimeException(
                        "Purge {$entity} tidak lengkap: {$deleted} terhapus, {$expected} diharapkan.",
                    );
                }
            }

            // This state transition is deliberately committed with the deletes.
            // A worker crash can therefore never leave deleted rows recorded as
            // merely running or failed.
            $lockedExport->forceFill([
                'purge_status' => 'completed',
                'purged_counts' => $counts,
                'purged_at' => now(),
                'error_message' => null,
            ])->save();

            return $counts;
        }, 3);
    }

    private function assertWithinRowLimit(DataExport $export): void
    {
        // The safety cap covers the full verification/locking workload, not
        // only rows ultimately deleted. Child-only scopes still scan and lock
        // their parent alerts to prevent a source race.
        $entities = $this->archives->includedEntities($export);

        $plannedRows = array_sum(array_map(
            fn (string $entity): int => (int) data_get($export->record_counts, $entity, 0),
            $entities,
        ));
        $maximumRows = max(1, (int) config('data_exports.purge_max_rows', 100_000));

        if ($plannedRows > $maximumRows) {
            throw new RuntimeException(
                "Purge mencakup {$plannedRows} baris, melebihi batas aman {$maximumRows} baris.",
            );
        }
    }
}
