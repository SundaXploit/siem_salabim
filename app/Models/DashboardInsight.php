<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardInsight extends Model
{
    protected $fillable = [
        'period_key',
        'access_scope',
        'period_start',
        'period_end',
        'status',
        'summary',
        'source_snapshot',
        'source_fingerprint',
        'provider',
        'model',
        'trigger',
        'requested_by_user_id',
        'generated_at',
        'next_refresh_at',
        'error_message',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'summary' => EncryptedString::class,
        'source_snapshot' => EncryptedJson::class,
        'generated_at' => 'datetime',
        'next_refresh_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
