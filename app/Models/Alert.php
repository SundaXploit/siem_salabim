<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Alert extends Model
{
    protected $fillable = [
        'wazuh_alert_id',
        'source_cluster',
        'source_index',
        'source_id',
        'source_identity_hash',
        'rule_description',
        'src_ip',
        'dst_ip',
        'agent_name',
        'rule_id',
        'rule_level',
        'raw_data',
        'status',
        'first_seen_at',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'rule_level' => 'integer',
        'first_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Alert $alert): void {
            // Keep legacy factories, seeders and integrations compatible while
            // every OpenSearch import supplies the concrete source identity.
            $alert->source_cluster ??= (string) config('services.opensearch.source_cluster', 'wazuh-primary');
            $alert->source_index ??= 'legacy';
            $alert->source_id ??= $alert->wazuh_alert_id;
            $alert->source_identity_hash = self::sourceIdentityHash(
                (string) $alert->source_cluster,
                (string) $alert->source_index,
                (string) $alert->source_id,
            );
        });
    }

    public static function sourceIdentityHash(string $cluster, string $index, string $sourceId): string
    {
        $payload = pack('N', strlen($cluster)).$cluster
            .pack('N', strlen($index)).$index
            .pack('N', strlen($sourceId)).$sourceId;

        return hash('sha256', $payload);
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function triages(): HasMany
    {
        return $this->hasMany(AlertTriage::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(AlertAiAnalysis::class);
    }

    public function latestAiAnalysis(): HasOne
    {
        return $this->hasOne(AlertAiAnalysis::class)->latestOfMany('generated_at');
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'ignored');
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeAcknowledged($query)
    {
        return $query->where('status', 'acknowledged');
    }

    public function scopeIgnored($query)
    {
        return $query->where('status', 'ignored');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'new' => 'badge-new',
            'acknowledged' => 'badge-acknowledged',
            'ignored' => 'badge-ignored',
            default => 'badge-new',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'new' => 'NEW',
            'acknowledged' => 'ACK',
            'ignored' => 'IGNORED',
            default => 'NEW',
        };
    }

    public function getLevelBadgeClassAttribute(): string
    {
        if ($this->rule_level >= 15) {
            return 'level-critical';
        }
        if ($this->rule_level >= 12) {
            return 'level-high';
        }
        if ($this->rule_level >= 7) {
            return 'level-medium';
        }

        return 'level-low';
    }
}
