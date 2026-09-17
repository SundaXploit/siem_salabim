<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class Configuration extends Model
{
    private const SECRET_KEYS = [
        'opensearch_password',
        'telegram_bot_token',
        'ai_api_key',
    ];

    public $timestamps = false;
    const UPDATED_AT = 'updated_at';

    protected $fillable = ['key', 'value'];

    protected $primaryKey = 'id';

    /**
     * Get a config value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $config = static::where('key', $key)->first();

        if (!$config) {
            return $default;
        }

        return static::decryptIfSecret($key, $config->value);
    }

    /**
     * Set a config value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        $storedValue = static::encryptIfSecret($key, $value);

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $storedValue]
        );
    }

    /**
     * Get telegram chat IDs as array.
     */
    public static function getTelegramChatIds(): array
    {
        $raw = static::get('telegram_chat_ids', '[]');
        return array_values(json_decode($raw, true) ?? []);
    }

    private static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    private static function encryptIfSecret(string $key, mixed $value): mixed
    {
        if (!static::isSecret($key) || $value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString((string) $value);
    }

    private static function decryptIfSecret(string $key, mixed $value): mixed
    {
        if (!static::isSecret($key) || !is_string($value) || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            // Existing installations may still contain plaintext values. They
            // remain readable and will be encrypted the next time they are saved.
            return $value;
        }
    }
}
