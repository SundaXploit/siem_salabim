<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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

    protected $attributes = ['role' => 'analyst', 'allowed_agents' => '[]', 'privileges' => '[]'];

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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'allowed_agents' => 'array',
            'privileges' => 'array',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAnalyst(): bool
    {
        return $this->role === 'analyst';
    }

    public function hasPrivilege(string $privilege): bool
    {
        return $this->isAdmin() || ($this->isAnalyst()
            && ($privilege === 'alerts.view' || in_array($privilege, $this->privileges ?? [], true)));
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
