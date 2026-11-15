<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const PRIVILEGES = [
        'alerts.view' => 'Lihat alert dan riwayat',
        'alerts.acknowledge' => 'ACK alert',
        'alerts.ignore' => 'Abaikan alert',
        'alerts.notify' => 'Kirim notifikasi Telegram',
        'alerts.analyze' => 'Jalankan analisis AI',
    ];

    protected $attributes = ['role' => 'analyst', 'is_active' => true, 'allowed_agents' => '[]', 'privileges' => '[]'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'allowed_agents',
        'privileges',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'session_version',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'allowed_agents' => 'array',
            'privileges' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->session_version = Str::random(64);
        });
        static::updating(function (User $user): void {
            if ($user->isDirty(['password', 'is_active', 'role', 'allowed_agents', 'privileges'])) {
                $user->session_version = Str::random(64);
                $user->remember_token = null;
            }
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public static function lockForAction(int $id): self
    {
        $model = new static;
        $connection = $model->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new \LogicException('Kunci tindakan pengguna harus berada dalam transaksi.');
        }
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite ignores FOR UPDATE. Reserve its writer before reading to
            // avoid two DEFERRED snapshots racing to upgrade their read locks.
            $connection->table($model->getTable())->where('id', $id)->update(['id' => $connection->raw('id')]);
        }

        return static::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function isAnalyst(): bool
    {
        return $this->role === 'analyst';
    }

    public function hasPrivilege(string $privilege): bool
    {
        return $this->is_active && ($this->isAdmin() || ($this->isAnalyst()
            && ($privilege === 'alerts.view' || in_array($privilege, $this->privileges ?? [], true))));
    }

    public function agentAccessKeys(): array
    {
        return array_values(array_unique(array_column($this->allowed_agents ?? [], 'key')));
    }

    public function alertAccessFingerprint(): string
    {
        if ($this->isAdmin()) {
            return 'all';
        }
        $keys = $this->agentAccessKeys();
        sort($keys, SORT_STRING);

        return hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR));
    }

    public function canAccessAlert(Alert $alert): bool
    {
        return $this->hasPrivilege('alerts.view') && ($this->isAdmin()
            || ($alert->agent_scope_key !== null && in_array($alert->agent_scope_key, $this->agentAccessKeys(), true)));
    }

    public function triages()
    {
        return $this->hasMany(AlertTriage::class);
    }

    public function notificationLogs()
    {
        return $this->hasMany(NotificationLog::class);
    }
}
