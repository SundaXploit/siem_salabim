<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function update(User $actor, User $target, array $data): User
    {
        return $this->change($actor, $target, function (User $locked, $admins) use ($actor, $data): void {
            abort_if($locked->isAdmin() && $actor->id !== $locked->id, 403, 'Profil admin lain tidak dapat diubah.');
            $this->assertAdminRemains($locked, $admins, $data['role'], $locked->is_active);
            $locked->fill($data)->save();
        });
    }

    public function setActive(User $actor, User $target, bool $active): User
    {
        return $this->change($actor, $target, function (User $locked, $admins) use ($actor, $active): void {
            if (! $active && $locked->id === $actor->id) {
                throw ValidationException::withMessages(['is_active' => 'Akun sendiri tidak dapat dinonaktifkan.']);
            }
            $this->assertAdminRemains($locked, $admins, $locked->role, $active);
            if ($locked->is_active === $active) {
                return;
            }
            $locked->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $alerts = Alert::where('acknowledged_by_user_id', $locked->id)->where('status', 'acknowledged')
                    ->orderBy('id')->lockForUpdate()->get();
                foreach ($alerts as $alert) {
                    Alert::whereKey($alert->id)->update([
                        'status' => 'new', 'acknowledged_by_user_id' => null, 'acknowledged_at' => null,
                    ]);
                    ActivityLog::record('ack_released', $actor, 'Alert #'.$alert->id, 'success', [
                        'previous_owner_id' => $locked->id, 'reason' => 'account_deactivated',
                    ]);
                }
            }
        });
    }

    private function change(User $actor, User $target, callable $change): User
    {
        return DB::transaction(function () use ($actor, $target, $change): User {
            if (DB::connection()->getDriverName() === 'sqlite') User::lockForAction($actor->id);
            // Serialize administrator membership changes, then lock the target.
            $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            $currentActor = $admins->firstWhere('id', $actor->id);
            abort_unless($currentActor?->is_active, 403, 'Akses hanya untuk admin aktif.');
            $locked = $admins->firstWhere('id', $target->id) ?? User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $change($locked, $admins);

            return $locked;
        }, 3);
    }

    private function assertAdminRemains(User $target, $admins, string $role, bool $active): void
    {
        if ($target->isAdmin() && $target->is_active && ($role !== 'admin' || ! $active)
            && $admins->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Admin aktif terakhir tidak dapat diturunkan role-nya atau dinonaktifkan.']);
        }
    }
}
