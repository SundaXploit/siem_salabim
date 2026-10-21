<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationUpdate extends Model
{
    protected $fillable = [
        'uuid',
        'requested_by_user_id',
        'requested_by_name',
        'from_version',
        'target_version',
        'release_id',
        'release_tag',
        'release_url',
        'asset_id',
        'asset_name',
        'asset_api_url',
        'asset_size',
        'asset_sha256',
        'status',
        'phase',
        'backup_path',
        'started_at',
        'completed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'release_id' => 'integer',
        'asset_id' => 'integer',
        'asset_size' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationUpdateEvent::class);
    }
}
