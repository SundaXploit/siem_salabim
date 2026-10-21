<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExport extends Model
{
    protected $fillable = [
        'uuid',
        'requested_by_user_id',
        'requested_by_name',
        'scope',
        'format',
        'status',
        'period_start',
        'period_end',
        'period_label',
        'source_snapshot_at',
        'snapshot_boundaries',
        'record_counts',
        'source_fingerprint',
        'storage_disk',
        'storage_path',
        'manifest_path',
        'filename',
        'checksum',
        'manifest_checksum',
        'file_size',
        'verified_at',
        'purge_status',
        'purge_requested_by_user_id',
        'purge_requested_by_name',
        'purge_requested_at',
        'purge_started_at',
        'purged_at',
        'purged_counts',
        'error_message',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'source_snapshot_at' => 'datetime',
        'snapshot_boundaries' => 'array',
        'record_counts' => 'array',
        'file_size' => 'integer',
        'verified_at' => 'datetime',
        'purge_requested_at' => 'datetime',
        'purge_started_at' => 'datetime',
        'purged_at' => 'datetime',
        'purged_counts' => 'array',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function purgeRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purge_requested_by_user_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified' && $this->verified_at !== null;
    }
}
