<?php

namespace App\Services;

use App\Models\ApplicationUpdate;
use Illuminate\Support\Facades\DB;

class ApplicationUpdateAudit
{
    /** @param array<string, mixed> $attributes */
    public function transition(
        ApplicationUpdate $update,
        string $phase,
        string $message,
        array $attributes = [],
        string $level = 'info',
    ): ApplicationUpdate {
        return DB::transaction(function () use ($update, $phase, $message, $attributes, $level): ApplicationUpdate {
            $locked = ApplicationUpdate::query()->lockForUpdate()->findOrFail($update->getKey());
            $locked->forceFill([
                'phase' => $phase,
                ...$attributes,
            ])->save();
            $locked->events()->create([
                'phase' => $phase,
                'level' => $level,
                'message' => $message,
                'context' => null,
            ]);

            return $locked;
        }, 3);
    }
}
