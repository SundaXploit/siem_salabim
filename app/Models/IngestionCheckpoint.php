<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class IngestionCheckpoint extends Model
{
    protected $fillable = [
        'stream_key',
        'source_cluster',
        'source_index',
        'min_level',
        'cursor_at',
        'last_completed_at',
    ];

    protected $casts = [
        'min_level' => 'integer',
    ];

    /**
     * Checkpoints are UTC watermarks. Database datetime columns do not carry
     * an offset, so Laravel's default datetime cast would reinterpret them in
     * APP_TIMEZONE (Asia/Jakarta) after a model reload.
     */
    protected function cursorAt(): Attribute
    {
        return $this->utcDateTimeAttribute();
    }

    protected function lastCompletedAt(): Attribute
    {
        return $this->utcDateTimeAttribute();
    }

    private function utcDateTimeAttribute(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?CarbonImmutable => $value === null
                ? null
                : CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC'),
            set: static function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                $date = $value instanceof DateTimeInterface
                    ? CarbonImmutable::instance($value)
                    : CarbonImmutable::parse((string) $value, 'UTC');

                return $date->utc()->format('Y-m-d H:i:s');
            },
        );
    }
}
