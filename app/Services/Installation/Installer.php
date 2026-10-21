<?php

namespace App\Services\Installation;

use App\Exceptions\InstallationException;
use App\Models\Configuration;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class Installer
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly EnvironmentFileWriter $environment,
        private readonly InstallationConnectionTester $connections,
    ) {}

    /** @param array<string, mixed> $input */
    public function install(array $input): void
    {
        $data = $this->validated($input);
        $lock = $this->acquireLock();

        try {
            if ($this->state->isInstalled()) {
                throw ValidationException::withMessages([
                    'installation' => 'Aplikasi sudah terpasang. Installer telah dikunci.',
                ]);
            }

            $database = $this->connections->testDatabase($data);
            if ($data['opensearch_enabled']) {
                $this->connections->testOpenSearch($data);
            }
            if ($data['telegram_enabled']) {
                $this->connections->testTelegram(
                    $data['telegram_bot_token'],
                    $data['telegram_chat_ids'],
                );
            }

            // Persist only after every required endpoint has passed its
            // read-only connection test. The writer uses a lock and atomic
            // replacement, so a concurrent request cannot expose a partial env.
            $this->environment->update($this->environmentValues($data));
            $this->applyRuntimeConfiguration($data, $database);

            // A release may have been deployed with a cache built from
            // `.env.example`. Leaving it in place would make the next request
            // ignore the database and URL selected by the installer.
            if (app()->configurationIsCached() && Artisan::call('config:clear') !== 0) {
                throw new InstallationException('Cache konfigurasi lama tidak dapat dihapus. Jalankan php artisan config:clear lalu ulangi instalasi.');
            }

            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                throw new InstallationException('Migrasi database gagal. Installer dapat dijalankan ulang setelah penyebabnya diperbaiki.');
            }

            $admin = DB::connection()->transaction(function () use ($data): User {
                $admin = User::query()->create([
                    'name' => $data['admin_name'],
                    'email' => strtolower($data['admin_email']),
                    'email_verified_at' => now(),
                    'password' => $data['admin_password'],
                    'role' => 'admin',
                ]);

                if ($data['opensearch_enabled']) {
                    Configuration::set('opensearch_host', rtrim($data['opensearch_host'], '/'));
                    Configuration::set('opensearch_username', $data['opensearch_username']);
                    Configuration::set('opensearch_password', $data['opensearch_password']);
                    Configuration::set('opensearch_index', $data['opensearch_index']);
                }
                if ($data['telegram_enabled']) {
                    Configuration::set('telegram_bot_token', $data['telegram_bot_token']);
                    Configuration::set('telegram_chat_ids', json_encode(
                        array_values(array_unique($data['telegram_chat_ids'])),
                        JSON_THROW_ON_ERROR,
                    ));
                }

                if ($data['ai_enabled']) {
                    Configuration::set('ai_api_key', $data['ai_api_key']);
                    Configuration::set('ai_base_url', rtrim($data['ai_base_url'], '/'));
                    Configuration::set('ai_model', $data['ai_model']);
                }

                return $admin;
            }, 3);

            // The one-time bootstrap secret disappears before the marker is
            // committed. The marker is deliberately the last state mutation.
            $this->state->removeToken();
            $this->state->markInstalled([
                'installed_at' => now()->toIso8601String(),
                'admin_user_id' => $admin->getKey(),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Database/endpoint exceptions can carry connection strings or
            // request URLs. Record only the class; never installer payloads.
            Log::error('[Installer] installation failed', [
                'exception_class' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                'installation' => $exception instanceof InstallationException
                    ? $exception->getMessage()
                    : 'Instalasi gagal tanpa menyimpan marker. Periksa log server lalu jalankan ulang installer.',
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $input */
    private function validated(array $input): array
    {
        $input['opensearch_enabled'] = filter_var($input['opensearch_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $input['telegram_enabled'] = filter_var($input['telegram_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $input['ai_enabled'] = filter_var($input['ai_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $input['smtp_enabled'] = filter_var($input['smtp_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $input['opensearch_verify_ssl'] = filter_var(
            $input['opensearch_verify_ssl'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

        $validator = Validator::make($input, [
            'app_name' => ['required', 'string', 'max:100'],
            'app_url' => ['required', 'url:http,https', 'max:2048'],
            'app_timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],

            'db_connection' => ['required', Rule::in(['pgsql', 'mysql', 'mariadb'])],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:255'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:4096'],
            'db_sslmode' => ['nullable', Rule::in(['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'])],

            'opensearch_enabled' => ['boolean'],
            'opensearch_host' => ['exclude_unless:opensearch_enabled,true', 'required', 'url:http,https', 'max:2048'],
            'opensearch_username' => ['exclude_unless:opensearch_enabled,true', 'required', 'string', 'max:255'],
            'opensearch_password' => ['exclude_unless:opensearch_enabled,true', 'required', 'string', 'max:4096'],
            'opensearch_index' => ['exclude_unless:opensearch_enabled,true', 'required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9_.\-*,]+\z/'],
            'opensearch_source_cluster' => ['exclude_unless:opensearch_enabled,true', 'required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_.-]+\z/'],
            'opensearch_verify_ssl' => ['exclude_unless:opensearch_enabled,true', 'boolean'],

            'telegram_enabled' => ['boolean'],
            'telegram_bot_token' => ['exclude_unless:telegram_enabled,true', 'required', 'string', 'max:4096', 'regex:/\A\d{5,20}:[A-Za-z0-9_-]{20,}\z/'],
            'telegram_chat_ids' => ['exclude_unless:telegram_enabled,true', 'required', 'array', 'min:1', 'max:20'],
            'telegram_chat_ids.*' => ['exclude_unless:telegram_enabled,true', 'required', 'string', 'max:100'],

            'ai_enabled' => ['boolean'],
            'ai_api_key' => [Rule::requiredIf($input['ai_enabled']), 'nullable', 'string', 'max:4096'],
            'ai_base_url' => [Rule::requiredIf($input['ai_enabled']), 'nullable', 'url:https', 'max:2048'],
            'ai_model' => [Rule::requiredIf($input['ai_enabled']), 'nullable', 'string', 'max:255'],

            'smtp_enabled' => ['boolean'],
            'mail_host' => [Rule::requiredIf($input['smtp_enabled']), 'nullable', 'string', 'max:255'],
            'mail_port' => [Rule::requiredIf($input['smtp_enabled']), 'nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:4096'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'mail_from_address' => [Rule::requiredIf($input['smtp_enabled']), 'nullable', 'email', 'max:255'],
            'mail_from_name' => [Rule::requiredIf($input['smtp_enabled']), 'nullable', 'string', 'max:255'],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
        ], [
            'required_if' => 'Kolom :attribute wajib diisi ketika fitur terkait diaktifkan.',
        ]);

        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    private function environmentValues(array $data): array
    {
        $secureSessionCookie = strtolower((string) parse_url($data['app_url'], PHP_URL_SCHEME)) === 'https';
        $mailScheme = match ($data['mail_encryption'] ?? null) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            default => null,
        };

        return [
            'APP_NAME' => $data['app_name'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim($data['app_url'], '/'),
            'APP_TIMEZONE' => $data['app_timezone'],
            'APP_LOCALE' => 'id',
            'APP_FALLBACK_LOCALE' => 'id',
            'SESSION_ENCRYPT' => 'true',
            'SESSION_SECURE_COOKIE' => $secureSessionCookie ? 'true' : 'false',
            'DB_CONNECTION' => $data['db_connection'],
            'DB_URL' => null,
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => (string) $data['db_port'],
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'] ?? '',
            'DB_SSLMODE' => $data['db_sslmode'] ?? 'prefer',
            'OPENSEARCH_SOURCE_CLUSTER' => $data['opensearch_source_cluster'] ?? 'wazuh-primary',
            'OPENSEARCH_VERIFY_SSL' => ($data['opensearch_verify_ssl'] ?? true) ? 'true' : 'false',
            'MAIL_MAILER' => $data['smtp_enabled'] ? 'smtp' : 'log',
            'MAIL_URL' => null,
            'MAIL_SCHEME' => $data['smtp_enabled'] ? $mailScheme : null,
            'MAIL_HOST' => $data['smtp_enabled'] ? $data['mail_host'] : '127.0.0.1',
            'MAIL_PORT' => $data['smtp_enabled'] ? (string) $data['mail_port'] : '2525',
            'MAIL_USERNAME' => $data['smtp_enabled'] ? ($data['mail_username'] ?? null) : null,
            'MAIL_PASSWORD' => $data['smtp_enabled'] ? ($data['mail_password'] ?? null) : null,
            'MAIL_FROM_ADDRESS' => $data['smtp_enabled'] ? $data['mail_from_address'] : $data['admin_email'],
            'MAIL_FROM_NAME' => $data['smtp_enabled'] ? $data['mail_from_name'] : $data['app_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $database
     */
    private function applyRuntimeConfiguration(array $data, array $database): void
    {
        config([
            'app.name' => $data['app_name'],
            'app.url' => rtrim($data['app_url'], '/'),
            'app.timezone' => $data['app_timezone'],
            'services.opensearch.source_cluster' => $data['opensearch_source_cluster'] ?? 'wazuh-primary',
            'services.opensearch.verify_ssl' => $data['opensearch_verify_ssl'] ?? true,
        ]);

        date_default_timezone_set($data['app_timezone']);
        $this->connections->activateDatabase($database);
    }

    /** @return resource */
    private function acquireLock()
    {
        $path = storage_path('framework/siem-installation.lock');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages([
                'installation' => 'Direktori storage tidak dapat ditulis oleh server web.',
            ]);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw ValidationException::withMessages([
                'installation' => 'Proses instalasi lain sedang berjalan. Tunggu hingga selesai lalu muat ulang halaman.',
            ]);
        }

        return $handle;
    }
}
