<?php

namespace App\Casts;

use App\Services\EncryptedSearchIndex;
use App\Services\SensitiveDataCipher;
use Illuminate\Database\Eloquent\Model;

class EncryptedIp extends EncryptedString
{
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $ip = EncryptedSearchIndex::ip($value);

        return [$key => SensitiveDataCipher::encryptString($ip),
            $key.'_hash' => $ip === null ? null : EncryptedSearchIndex::hash('ip', $ip)];
    }
}
