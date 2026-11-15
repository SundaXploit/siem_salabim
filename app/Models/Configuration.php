<?php

namespace App\Models;

use App\Services\SensitiveDataCipher;
use Illuminate\Database\Eloquent\Model;
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

        if (! $config) {
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
        return array_values(array_unique(array_column(static::getTelegramDestinations(), 'chat_id')));
    }

    public static function getTelegramDestinations(): array
    {
        $saved = static::get('telegram_destinations');
        if ($saved !== null) {
            $destinations = json_decode($saved, true);

            return is_array($destinations) ? array_values($destinations) : [];
        }

        $legacyIds = json_decode(static::get('telegram_chat_ids', '[]'), true);
        $destinations = [];
        foreach (is_array($legacyIds) ? $legacyIds : [] as $chatId) {
            if (is_string($chatId) && trim($chatId) !== '') {
                $destinations[] = ['label' => 'Tujuan '.(count($destinations) + 1), 'chat_id' => trim($chatId), 'agent_keys' => []];
            }
        }

        return $destinations;
    }

    public static function telegramDestinationsForAgent(?string $agent, ?array $destinations = null): array
    {
        if ($agent === null || ! preg_match('/\A[a-f0-9]{64}\z/', $agent)) {
            return [];
        }

        $matched = [];
        foreach ($destinations ?? static::getTelegramDestinations() as $destination) {
            if (is_array($destination) && is_string($destination['label'] ?? null)
                && is_string($destination['chat_id'] ?? null) && $destination['chat_id'] !== ''
                && is_array($destination['agent_keys'] ?? null) && in_array($agent, $destination['agent_keys'], true)) {
                $matched[$destination['chat_id']] = $destination;
            }
        }

        return array_values($matched);
    }

    private static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    private static function encryptIfSecret(string $key, mixed $value): mixed
    {
        if (! static::isSecret($key) || $value === null || $value === '') {
            return $value;
        }

        return Crypt::encryptString((string) $value);
    }

    private static function decryptIfSecret(string $key, mixed $value): mixed
    {
        if (! static::isSecret($key) || ! is_string($value) || $value === '') {
            return $value;
        }

        return SensitiveDataCipher::decryptString($value);
    }
}
