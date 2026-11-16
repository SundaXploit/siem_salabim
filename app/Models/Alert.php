<?php

namespace App\Models;

use App\Casts\EncryptedIp;
use App\Casts\EncryptedJson;
use App\Casts\UtcDateTime;
use App\Services\EncryptedSearchIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Alert extends Model
{
    public const LIST_COLUMNS = ['id', 'wazuh_alert_id', 'source_cluster', 'source_index', 'source_id', 'source_identity_hash',
        'rule_description', 'src_ip', 'dst_ip', 'agent_name', 'agent_scope_key', 'rule_id', 'rule_level', 'status',
        'first_seen_at', 'acknowledged_by_user_id', 'acknowledged_at', 'created_at', 'updated_at'];

    use Concerns\IndexesEncryptedSearch;

    protected string $encryptedSearchEntity = 'alert';

    protected array $encryptedSearchFields = ['raw_data', 'src_ip', 'dst_ip'];

    protected $hidden = ['src_ip_hash', 'dst_ip_hash'];

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
        'agent_scope_key',
        'rule_id',
        'rule_level',
        'raw_data',
        'status',
        'first_seen_at',
    ];

    protected $casts = [
        'raw_data' => EncryptedJson::class,
        'mitre_metadata' => 'array',
        'src_ip' => EncryptedIp::class,
        'dst_ip' => EncryptedIp::class,
        'rule_level' => 'integer',
        'first_seen_at' => UtcDateTime::class,
    ];

    public function setRuleDescriptionAttribute(?string $value): void
    {
        // PostgreSQL text cannot hold NUL. Escape display metadata so PDO
        // cannot silently truncate it; raw_data retains the original evidence.
        $this->attributes['rule_description'] = $value === null ? null : str_replace("\0", '\\u0000', $value);
    }

    public function setAgentNameAttribute(?string $value): void
    {
        $this->attributes['agent_name'] = $value === null ? null : str_replace("\0", '\\u0000', $value);
    }

    protected static function booted(): void
    {
        static::saving(function (Alert $alert): void {
            if (! $alert->exists || $alert->isDirty('raw_data')) {
                $alert->mitre_metadata = self::mitreMetadata($alert->raw_data);
            }
        });
        static::creating(function (Alert $alert): void {
            // Keep legacy factories, seeders and integrations compatible while
            // every OpenSearch import supplies the concrete source identity.
            $alert->source_cluster ??= (string) config('services.opensearch.source_cluster', 'wazuh-primary');
            $alert->agent_scope_key = self::agentScopeKey($alert->source_cluster, data_get($alert->raw_data, 'agent.id'));
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

    public static function mitreMetadata(?array $raw): array
    {
        $mitre = data_get($raw, 'rule.mitre', []);
        if (! is_array($mitre)) {
            return [];
        }
        $list = fn ($value) => array_slice(array_values(array_filter(is_array($value) ? $value : [$value], 'is_string')), 0, 128);

        return ['id' => array_values(array_filter($list($mitre['id'] ?? []), fn ($id) => preg_match('/\AT\d{4}(?:\.\d{3})?\z/', $id))),
            'technique' => array_map(fn ($name) => mb_substr($name, 0, 256), $list($mitre['technique'] ?? []))];
    }

    public static function agentScopeKey(string $cluster, mixed $agentId): ?string
    {
        if ((! is_string($agentId) && ! is_int($agentId)) || (string) $agentId === '') {
            return null;
        }

        return self::sourceIdentityHash($cluster, 'agent', (string) $agentId);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->hasPrivilege('alerts.view')) {
            return $query->whereRaw('1 = 0');
        }

        return $user->isAdmin() ? $query : $query->whereIn(
            $query->getModel()->qualifyColumn('agent_scope_key'),
            $user->hasPrivilege('alerts.view') ? $user->agentAccessKeys() : [],
        );
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isAcknowledgedBy(User $user): bool
    {
        return $this->status === 'acknowledged' && (int) $this->acknowledged_by_user_id === (int) $user->id;
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        return auth()->user() ? $query->visibleTo(auth()->user()) : $query;
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

    public function scopeSearchTerms(Builder $query, string $search): Builder
    {
        $terms = array_slice(preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 8);
        foreach ($terms as $term) {
            if (filter_var($term, FILTER_VALIDATE_IP)) {
                $ip = EncryptedSearchIndex::ip($term);
                $hash = EncryptedSearchIndex::hash('ip', $ip);
                $query->where(function (Builder $match) use ($ip, $hash): void {
                    $lookups = DB::table('alerts')->select('id')->where('src_ip_hash', $hash)
                        ->union(DB::table('alerts')->select('id')->where('dst_ip_hash', $hash))
                        ->union(DB::table('encrypted_search_tokens')->select('record_id')
                            ->where('entity', 'alert')->where('token_hash', EncryptedSearchIndex::hash('search', $ip)));
                    // Materialize the UNION once. MySQL otherwise rewrites it
                    // as a dependent lookup for every alert and misses the hash indexes.
                    $match->whereIn('alerts.id', DB::query()->fromSub($lookups, 'sensitive_ip_matches')->select('id'));
                    if (config('data_security.allow_legacy_plaintext', true)) {
                        $match->orWhere(fn ($legacy) => $legacy->whereNull('src_ip_hash')->where('src_ip', $ip))
                            ->orWhere(fn ($legacy) => $legacy->whereNull('dst_ip_hash')->where('dst_ip', $ip));
                    }
                });

                continue;
            }
            $query->where(function (Builder $match) use ($term): void {
                $like = '%'.$term.'%';
                foreach (['rule_description', 'agent_name', 'rule_id', 'wazuh_alert_id', 'status'] as $column) {
                    $match->orWhere($column, 'like', $like);
                }
                $ip = EncryptedSearchIndex::ip($term);
                $hash = EncryptedSearchIndex::hash('ip', $ip);
                $match->orWhere('src_ip_hash', $hash)->orWhere('dst_ip_hash', $hash)
                    ->orWhereExists(fn ($tokens) => EncryptedSearchIndex::match($tokens, 'alert', 'alerts.id', $term));
                if (ctype_digit($term)) {
                    $match->orWhere('id', (int) $term);
                }
                // Transitional reads only; strict mode removes the old JSON scan.
                if (config('data_security.allow_legacy_plaintext', true)) {
                    $raw = in_array($match->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT';
                    $match->orWhere(fn ($legacy) => $legacy->whereNull('src_ip_hash')->where('src_ip', $ip))
                        ->orWhere(fn ($legacy) => $legacy->whereNull('dst_ip_hash')->where('dst_ip', $ip))
                        ->orWhere(fn ($legacy) => $legacy
                            ->whereRaw("(LTRIM(CAST(raw_data AS {$raw})) LIKE '{%' OR LTRIM(CAST(raw_data AS {$raw})) LIKE '[%')")
                            ->whereRaw('CAST(raw_data AS '.$raw.') LIKE ?', [$like]));
                }
            });
        }

        return $query;
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
