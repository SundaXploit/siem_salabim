<?php

namespace App\Models;

use App\Casts\EncryptedJson;
use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertAiAnalysis extends Model
{
    protected $fillable = [
        'requested_by_user_id',
        'verdict',
        'confidence',
        'summary',
        'supporting_indicators',
        'legitimate_indicators',
        'recommended_checks',
        'limitations',
        'model',
        'duration_ms',
        'generated_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'summary' => EncryptedString::class,
        'supporting_indicators' => EncryptedJson::class,
        'legitimate_indicators' => EncryptedJson::class,
        'recommended_checks' => EncryptedJson::class,
        'limitations' => EncryptedJson::class,
        'duration_ms' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function toResult(): array
    {
        return [
            'verdict' => $this->verdict,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'supporting_indicators' => $this->supporting_indicators ?? [],
            'legitimate_indicators' => $this->legitimate_indicators ?? [],
            'recommended_checks' => $this->recommended_checks ?? [],
            'limitations' => $this->limitations ?? [],
            'model' => $this->model,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
        ];
    }
}
