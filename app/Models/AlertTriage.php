<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertTriage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'alert_id',
        'user_id',
        'action',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            'ignore'      => 'Diabaikan',
            'acknowledge' => 'Diakui',
            'notify'      => 'Notifikasi Dikirim',
            default       => $this->action,
        };
    }
}
