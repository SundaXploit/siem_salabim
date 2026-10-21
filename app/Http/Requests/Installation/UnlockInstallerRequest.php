<?php

namespace App\Http\Requests\Installation;

use Illuminate\Contracts\Validation\ValidationRule;

class UnlockInstallerRequest extends SecureInstallerRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'installation_token' => ['required', 'string', 'max:4096'],
        ];
    }

    public function attributes(): array
    {
        return [
            'installation_token' => 'token instalasi',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function sensitiveInputKeys(): array
    {
        return ['installation_token'];
    }
}
