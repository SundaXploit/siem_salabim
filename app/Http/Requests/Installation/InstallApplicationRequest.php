<?php

namespace App\Http\Requests\Installation;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class InstallApplicationRequest extends SecureInstallerRequest
{
    protected function prepareForValidation(): void
    {
        $trimmed = [];

        foreach ([
            'app_name',
            'app_url',
            'app_timezone',
            'db_host',
            'db_database',
            'db_username',
            'opensearch_host',
            'opensearch_username',
            'opensearch_index',
            'opensearch_source_cluster',
            'ai_base_url',
            'ai_model',
            'mail_host',
            'mail_username',
            'mail_from_address',
            'mail_from_name',
            'admin_name',
        ] as $key) {
            if (is_string($this->input($key))) {
                $trimmed[$key] = trim((string) $this->input($key));
            }
        }

        if (is_string($this->input('admin_email'))) {
            $trimmed['admin_email'] = Str::lower(trim((string) $this->input('admin_email')));
        }

        if (is_array($this->input('telegram_chat_ids'))) {
            $trimmed['telegram_chat_ids'] = array_values(array_filter(
                array_map(
                    static fn (mixed $chatId): string => trim((string) $chatId),
                    $this->input('telegram_chat_ids'),
                ),
                static fn (string $chatId): bool => $chatId !== '',
            ));
        }

        $trimmed['app_url'] = isset($trimmed['app_url'])
            ? rtrim($trimmed['app_url'], '/')
            : $this->input('app_url');
        $trimmed['opensearch_host'] = isset($trimmed['opensearch_host'])
            ? rtrim($trimmed['opensearch_host'], '/')
            : $this->input('opensearch_host');
        $trimmed['opensearch_verify_ssl'] = $this->boolean('opensearch_verify_ssl');
        $trimmed['ai_enabled'] = $this->boolean('ai_enabled');
        $trimmed['smtp_enabled'] = $this->boolean('smtp_enabled');
        $trimmed['mail_encryption'] = $this->input('mail_encryption') === 'none'
            ? null
            : $this->input('mail_encryption');

        $this->merge($trimmed);
    }

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
            'app_name' => ['required', 'string', 'max:100'],
            'app_url' => ['required', 'url', 'starts_with:http://,https://', 'max:2048'],
            'app_timezone' => ['required', 'timezone:all'],

            'db_connection' => ['required', Rule::in(['mysql', 'mariadb', 'pgsql'])],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:255'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:4096'],
            'db_sslmode' => ['nullable', Rule::in(['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'])],

            'opensearch_host' => ['required', 'url', 'starts_with:http://,https://', 'max:2048'],
            'opensearch_username' => ['required', 'string', 'max:255'],
            'opensearch_password' => ['required', 'string', 'max:4096'],
            'opensearch_index' => ['required', 'string', 'max:255'],
            'opensearch_source_cluster' => ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_.-]+\z/'],
            'opensearch_verify_ssl' => ['required', 'boolean'],

            'telegram_bot_token' => [
                'required',
                'string',
                'max:4096',
                'regex:/\A\d{5,20}:[A-Za-z0-9_-]{20,}\z/',
            ],
            'telegram_chat_ids' => ['required', 'array', 'min:1', 'max:20'],
            'telegram_chat_ids.*' => [
                'required',
                'string',
                'max:100',
                'distinct',
                'regex:/^(?:-?\d+|@[A-Za-z0-9_]{5,32})$/',
            ],

            'ai_enabled' => ['required', 'boolean'],
            'ai_api_key' => ['nullable', 'required_if:ai_enabled,true', 'string', 'max:4096'],
            'ai_base_url' => ['nullable', 'required_if:ai_enabled,true', 'url', 'starts_with:https://', 'max:2048'],
            'ai_model' => ['nullable', 'required_if:ai_enabled,true', 'string', 'max:255'],

            'smtp_enabled' => ['required', 'boolean'],
            'mail_host' => ['nullable', 'required_if:smtp_enabled,true', 'string', 'max:255'],
            'mail_port' => ['nullable', 'required_if:smtp_enabled,true', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:4096'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'mail_from_address' => ['nullable', 'required_if:smtp_enabled,true', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'required_if:smtp_enabled,true', 'string', 'max:255'],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => [
                'required',
                'confirmed',
                'max:72',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'admin_password_confirmation' => ['required', 'string', 'same:admin_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'telegram_chat_ids.required' => 'Tambahkan setidaknya satu tujuan chat Telegram.',
            'telegram_chat_ids.min' => 'Tambahkan setidaknya satu tujuan chat Telegram.',
            'telegram_chat_ids.*.regex' => 'ID chat Telegram harus berupa angka (boleh negatif) atau username channel diawali @.',
            'telegram_bot_token.regex' => 'Format token bot Telegram tidak valid.',
            'ai_api_key.required_if' => 'API key AI wajib diisi saat integrasi AI diaktifkan.',
            'mail_host.required_if' => 'Host SMTP wajib diisi saat SMTP diaktifkan.',
            'admin_password.confirmed' => 'Konfirmasi kata sandi administrator tidak cocok.',
        ];
    }

    public function attributes(): array
    {
        return [
            'app_name' => 'nama aplikasi',
            'app_url' => 'URL aplikasi',
            'app_timezone' => 'zona waktu',
            'db_connection' => 'jenis database',
            'db_host' => 'host database',
            'db_port' => 'port database',
            'db_database' => 'nama database',
            'db_username' => 'pengguna database',
            'db_password' => 'kata sandi database',
            'db_sslmode' => 'mode TLS PostgreSQL',
            'opensearch_host' => 'host OpenSearch',
            'opensearch_username' => 'pengguna OpenSearch',
            'opensearch_password' => 'kata sandi OpenSearch',
            'opensearch_index' => 'pola indeks OpenSearch',
            'opensearch_source_cluster' => 'identitas cluster OpenSearch',
            'telegram_bot_token' => 'token bot Telegram',
            'telegram_chat_ids' => 'tujuan chat Telegram',
            'ai_api_key' => 'API key AI',
            'ai_base_url' => 'endpoint AI',
            'ai_model' => 'model AI',
            'mail_host' => 'host SMTP',
            'mail_port' => 'port SMTP',
            'mail_from_address' => 'alamat pengirim',
            'mail_from_name' => 'nama pengirim',
            'admin_name' => 'nama administrator',
            'admin_email' => 'email administrator',
            'admin_password' => 'kata sandi administrator',
            'admin_password_confirmation' => 'konfirmasi kata sandi administrator',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function sensitiveInputKeys(): array
    {
        return [
            'db_password',
            'opensearch_password',
            'telegram_bot_token',
            'ai_api_key',
            'mail_password',
            'admin_password',
            'admin_password_confirmation',
        ];
    }
}
