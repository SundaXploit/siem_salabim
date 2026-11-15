<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertTriage extends Model
{
    use Concerns\IndexesEncryptedSearch;

    protected string $encryptedSearchEntity = 'triage';

    protected array $encryptedSearchFields = ['reason'];

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
        'reason' => EncryptedString::class,
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
        return match ($this->action) {
            'ignore' => 'Diabaikan',
            'acknowledge' => 'Diakui',
            'notify' => 'Notifikasi Dikirim',
            default => $this->action,
        };
    }
}
