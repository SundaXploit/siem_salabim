<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardInsight extends Model
{
    protected $fillable = [
        'period_key',
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
        'period_start'    => 'date',
        'period_end'      => 'date',
        'source_snapshot' => 'array',
        'generated_at'    => 'datetime',
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
