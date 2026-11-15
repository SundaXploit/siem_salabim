<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    public const CUSTOM_BODY = "🚨 <b>Judul notifikasi:</b> \n\n🖥️ <b>Agent:</b> \n🌐 <b>Source IP:</b> \n🎯 <b>Dest IP:</b> \n\n<b>🔎 Detail temuan:</b> \n\n<b>⚠️ Rekomendasi:</b> \n\n<b>Prioritas:</b> ";

    protected $fillable = ['name', 'category', 'body', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('category')->orderBy('name');
    }

    // ─── Template Rendering ───────────────────────────────────────────────────

    /**
     * Replace {variables} in body with actual alert data.
     */
    public function render(Alert $alert): string
    {
        $raw = $alert->raw_data ?? [];

        $vars = [
            '{alert_id}' => $alert->id,
            '{agent_id}' => $raw['agent']['id'] ?? '-',
            '{source_cluster}' => $alert->source_cluster ?? '-',
            '{rule_description}' => $alert->rule_description ?? '-',
            '{rule_id}' => $alert->rule_id ?? '-',
            '{rule_level}' => $alert->rule_level ?? '-',
            '{agent_name}' => $alert->agent_name ?? '-',
            '{src_ip}' => $alert->src_ip ?? '-',
            '{dst_ip}' => $alert->dst_ip ?? '-',
            '{timestamp}' => $alert->first_seen_at
                                        ? $alert->first_seen_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB'
                                        : '-',
            '{agent_ip}' => $raw['agent']['ip'] ?? '-',
            '{wazuh_id}' => $alert->wazuh_alert_id ?? '-',
        ];

        $vars = array_map(
            static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $vars,
        );

        return strtr($this->body, $vars);
    }

    // ─── Static helpers ───────────────────────────────────────────────────────

    public static function allActive(): Collection
    {
        return static::active()->get();
    }
}
