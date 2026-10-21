<?php

namespace App\Http\Requests\Installation;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class SecureInstallerRequest extends FormRequest
{
    /**
     * Remove installer credentials before Laravel builds a validation redirect.
     *
     * The global exception handler also excludes common password fields, but the
     * installer accepts additional secrets which must never be flashed to the
     * session after a validation failure.
     */
    protected function failedValidation(Validator $validator): never
    {
        $this->forgetSensitiveInput();

        parent::failedValidation($validator);
    }

    public function forgetSensitiveInput(): void
    {
        foreach ($this->sensitiveInputKeys() as $key) {
            $this->request->remove($key);
            $this->query->remove($key);

            if ($this->isJson()) {
                $this->json()->remove($key);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    abstract protected function sensitiveInputKeys(): array;
}
