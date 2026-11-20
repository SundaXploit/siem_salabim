<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class LocalTime
{
    public static function label(): string
    {
        return match (config('app.timezone')) {
            'Asia/Jakarta' => 'WIB',
            'Asia/Makassar' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => config('app.timezone'),
        };
    }

    /** Strings here represent normalized UTC event times, never raw source timestamps. */
    public static function format(DateTimeInterface|string|null $time, string $format = 'd/m/Y H:i:s'): string
    {
        if ($time === null || $time === '') {
            return '-';
        }

        $date = $time instanceof DateTimeInterface ? CarbonImmutable::instance($time) : CarbonImmutable::parse($time, 'UTC');

        return $date->setTimezone(config('app.timezone'))->format($format).' '.self::label();
    }
}
