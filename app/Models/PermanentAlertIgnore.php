<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermanentAlertIgnore extends Model
{
    public const WRITE_LOCK = 'permanent-alert-ignore:write';

    public $timestamps = false;

    protected $fillable = ['rule_id', 'created_by_user_id', 'created_by_name'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            $rule->created_at ??= now();
        });
    }

    public static function ruleIds(): array
    {
        return static::whereNotNull('rule_id')->orderBy('rule_id')->pluck('rule_id')->all();
    }
}
