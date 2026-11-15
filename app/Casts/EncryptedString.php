<?php

namespace App\Casts;

use App\Services\SensitiveDataCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class EncryptedString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return SensitiveDataCipher::decryptString($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return SensitiveDataCipher::encryptString($value === null ? null : (string) $value);
    }
}
