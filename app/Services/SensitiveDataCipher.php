<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class SensitiveDataCipher
{
    public const PREFIX = 'siem:enc:v1:';

    public static function encryptString(?string $value): ?string
    {
        return $value === null ? null : self::PREFIX.Crypt::encryptString($value);
    }

    public static function decryptString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (str_starts_with($value, self::PREFIX)) {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        }
        $envelope = json_decode(base64_decode($value, true) ?: '', true);
        // A partially damaged Laravel envelope is still ciphertext. Legacy
        // repair must authenticate it rather than re-encrypt it as plaintext.
        if (is_array($envelope) && count(array_intersect(['iv', 'value', 'mac', 'tag'], array_keys($envelope))) >= 2) {
            return Crypt::decryptString($value);
        }
        self::assertLegacyAllowed();

        return $value;
    }

    public static function encryptJson(mixed $value): ?string
    {
        return $value === null ? null : json_encode(self::encryptString(json_encode($value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), JSON_THROW_ON_ERROR);
    }

    public static function decryptJson(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (is_string($decoded) && str_starts_with($decoded, self::PREFIX)) {
            return json_decode(self::decryptString($decoded), true, 512, JSON_THROW_ON_ERROR);
        }
        self::assertLegacyAllowed();
        if (! is_array($decoded) && $decoded !== null) {
            throw new DecryptException('Invalid encrypted JSON payload.');
        }

        return $decoded;
    }

    private static function assertLegacyAllowed(): void
    {
        if (! config('data_security.allow_legacy_plaintext', false)) {
            throw new DecryptException('Legacy plaintext requires an explicit migration mode.');
        }
    }
}
