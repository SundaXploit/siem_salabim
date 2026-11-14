<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public $timestamps = false;

    public const ACTIONS = [
        'login' => 'Login',
        'logout' => 'Logout',
        'password_changed' => 'Ganti password',
        'password_reset' => 'Reset password',
        'profile_updated' => 'Ubah profil',
        'user_created' => 'Tambah user',
        'user_updated' => 'Ubah user / role',
        'user_deleted' => 'Hapus user',
        'settings_opened' => 'Buka Settings',
        'permanent_ignore_added' => 'Tambah ignore permanen',
        'permanent_ignore_removed' => 'Hapus ignore permanen',
        'opensearch_updated' => 'Simpan konfigurasi OpenSearch & Alert',
        'telegram_updated' => 'Simpan konfigurasi Telegram',
        'automatic_notifications_updated' => 'Simpan notifikasi otomatis',
        'ai_updated' => 'Simpan konfigurasi AI',
        'alerts_updated' => 'Simpan konfigurasi Alert',
        'opensearch_tested' => 'Tes koneksi OpenSearch',
        'telegram_tested' => 'Tes bot / pesan Telegram',
        'ai_tested' => 'Tes koneksi AI',
        'ai_models_loaded' => 'Ambil daftar model AI',
        'agents_loaded' => 'Ambil daftar agent',
        'fetch_requested' => 'Minta fetch alert',
        'data_previewed' => 'Preview data',
        'export_requested' => 'Buat ekspor data',
        'data_delete_requested' => 'Permintaan hapus data',
        'export_authorized' => 'Verifikasi unduhan ekspor',
        'export_downloaded' => 'Unduh ekspor data',
        'purge_requested' => 'Purge data dari backup',
        'settings_action' => 'Aktivitas Settings',
    ];

    protected $fillable = ['actor_id', 'actor_name', 'actor_role', 'action', 'subject', 'result', 'ip_address', 'context', 'created_at'];

    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];

    public static function record(string $action, ?User $actor, ?string $subject = null, string $result = 'success', array $context = []): self
    {
        return static::create([
            'actor_id' => $actor?->exists ? $actor->id : null,
            'actor_name' => $actor?->name ?? ($action === 'login' ? 'Pengguna tidak dikenal' : 'Sistem'),
            'actor_role' => $actor?->role,
            'action' => $action,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 255),
            'result' => $result,
            'ip_address' => request()->ip(),
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
