<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AlertDateFilter
{
    public static function apply($query, Request $request): void
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
        ]);
        if (filled($data['date_from'] ?? null)) {
            $query->where('first_seen_at', '>=', CarbonImmutable::parse($data['date_from'], 'Asia/Jakarta')->startOfDay()->utc()->format('Y-m-d H:i:s'));
        }
        if (filled($data['date_to'] ?? null)) {
            $query->where('first_seen_at', '<', CarbonImmutable::parse($data['date_to'], 'Asia/Jakarta')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s'));
        }
    }
}
