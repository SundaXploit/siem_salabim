<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['name', 'icon', 'category', 'body', 'is_active'];

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
     *
     * @param  \App\Models\Alert $alert
     * @return string
     */
    public function render(Alert $alert): string
    {
        $raw = $alert->raw_data ?? [];

        $vars = [
            '{rule_description}' => $alert->rule_description ?? '-',
            '{rule_id}'          => $alert->rule_id          ?? '-',
            '{rule_level}'       => $alert->rule_level       ?? '-',
            '{agent_name}'       => $alert->agent_name       ?? '-',
            '{src_ip}'           => $alert->src_ip           ?? '-',
            '{dst_ip}'           => $alert->dst_ip           ?? '-',
            '{timestamp}'        => $alert->first_seen_at
                                        ? $alert->first_seen_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') . ' WIB'
                                        : '-',
            '{agent_ip}'         => $raw['agent']['ip']     ?? '-',
            '{wazuh_id}'         => $alert->wazuh_alert_id  ?? '-',
        ];

        return str_replace(array_keys($vars), array_values($vars), $this->body);
    }

    // ─── Static helpers ───────────────────────────────────────────────────────

    public static function allActive(): \Illuminate\Database\Eloquent\Collection
    {
        return static::active()->get();
    }
}
