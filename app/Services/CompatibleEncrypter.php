<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;

class CompatibleEncrypter extends Encrypter
{
    public function decrypt($payload, $unserialize = true)
    {
        $envelope = is_string($payload) ? json_decode(base64_decode($payload, true) ?: '', true) : null;
        // Laravel authenticates CBC with a MAC, and GCM with its tag. Never
        // turn an authentication failure into a plaintext fallback.
        $cipher = is_array($envelope) && ! empty($envelope['tag']) ? 'aes-256-gcm' : 'aes-256-cbc';
        if (strtolower($this->cipher) === $cipher) {
            return parent::decrypt($payload, $unserialize);
        }

        return (new Encrypter($this->key, $cipher))->previousKeys($this->previousKeys)->decrypt($payload, $unserialize);
    }
}
