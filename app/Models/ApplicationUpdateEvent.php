<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationUpdateEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'phase',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function applicationUpdate(): BelongsTo
    {
        return $this->belongsTo(ApplicationUpdate::class);
    }
}
