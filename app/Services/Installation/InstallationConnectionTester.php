<?php

namespace App\Services\Installation;

use App\Exceptions\InstallationException;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class InstallationConnectionTester
{
    private const CONNECTION_NAME = 'siem_installer';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function testDatabase(array $input): array
    {
        $configuration = $this->databaseConfiguration($input);

        config(['database.connections.'.self::CONNECTION_NAME => $configuration]);
        DB::purge(self::CONNECTION_NAME);

        try {
            $connection = DB::connection(self::CONNECTION_NAME);
            $connection->getPdo();
            $connection->select('select 1');

            return $configuration;
        } catch (Throwable) {
            throw new InstallationException(
                'Koneksi database gagal. Periksa host, port, nama database, kredensial, TLS, dan izin pengguna database.'
            );
        } finally {
            DB::disconnect(self::CONNECTION_NAME);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function databaseConfiguration(array $input): array
    {
        $driver = strtolower(trim((string) ($input['db_connection'] ?? '')));

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new InstallationException('Driver database harus PostgreSQL, MySQL, atau MariaDB.');
        }

        /** @var array<string, mixed> $configuration */
        $configuration = config('database.connections.'.$driver, []);
        // DB_URL would override the explicit wizard fields. Driver-specific
        // PDO options (for example a MySQL CA) remain available.
        unset($configuration['url']);

        $configuration['driver'] = $driver;
        $configuration['host'] = trim((string) ($input['db_host'] ?? ''));
        $configuration['port'] = (string) ($input['db_port'] ?? '');
        $configuration['database'] = trim((string) ($input['db_database'] ?? ''));
        $configuration['username'] = trim((string) ($input['db_username'] ?? ''));
        $configuration['password'] = (string) ($input['db_password'] ?? '');

        if ($driver === 'pgsql') {
            $configuration['sslmode'] = trim((string) ($input['db_sslmode'] ?? 'prefer'));
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{count: int, index: string}
     */
    public function testOpenSearch(array $input): array
    {
        $host = rtrim(trim((string) ($input['opensearch_host'] ?? '')), '/');
        $username = trim((string) ($input['opensearch_username'] ?? ''));
        $password = (string) ($input['opensearch_password'] ?? '');
        $index = trim((string) ($input['opensearch_index'] ?? ''));
        $verifySsl = filter_var($input['opensearch_verify_ssl'] ?? true, FILTER_VALIDATE_BOOL);

        $parts = parse_url($host);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || $username === ''
            || $password === ''
            || ! preg_match('/\A[a-zA-Z0-9_.\-*,]+\z/', $index)
        ) {
            throw new InstallationException('Konfigurasi OpenSearch/Wazuh tidak lengkap atau tidak valid.');
        }

        try {
            $response = (new Client([
                'base_uri' => $host,
                'auth' => [$username, $password],
                'verify' => $verifySsl,
                'connect_timeout' => 5,
                'timeout' => 15,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]))->get('/'.$index.'/_count');

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new InstallationException('OpenSearch/Wazuh menolak kredensial atau akses ke indeks yang dipilih.');
            }

            $body = json_decode((string) $response->getBody(), true);

            return [
                'count' => (int) ($body['count'] ?? 0),
                'index' => $index,
            ];
        } catch (InstallationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InstallationException(
                'Tidak dapat terhubung ke OpenSearch/Wazuh. Periksa alamat, TLS, jaringan, dan kredensial.'
            );
        }
    }

    /**
     * Validate a Telegram bot and every destination without sending a message.
     *
     * @param  array<int, string>  $chatIds
     */
    public function testTelegram(string $token, array $chatIds): void
    {
        $token = trim($token);
        $chatIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $chatId): string => trim((string) $chatId),
            $chatIds,
        ))));

        if (! preg_match('/\A\d{5,20}:[A-Za-z0-9_-]{20,}\z/', $token) || $chatIds === []) {
            throw new InstallationException('Token bot dan minimal satu Chat ID Telegram wajib diisi.');
        }

        $client = new Client([
            'base_uri' => rtrim((string) config('services.telegram.base_url', 'https://api.telegram.org'), '/'),
            'connect_timeout' => 5,
            'timeout' => 15,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);

        try {
            $this->assertTelegramResponse($client->get('/bot'.$token.'/getMe'));

            foreach ($chatIds as $chatId) {
                $this->assertTelegramResponse($client->post('/bot'.$token.'/getChat', [
                    'form_params' => ['chat_id' => $chatId],
                ]));
            }
        } catch (InstallationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new InstallationException(
                'Tidak dapat memvalidasi Telegram. Periksa token, Chat ID, akses bot, DNS, TLS, dan jaringan.'
            );
        }
    }

    private function assertTelegramResponse(object $response): void
    {
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() < 200
            || $response->getStatusCode() >= 300
            || ! is_array($body)
            || ($body['ok'] ?? false) !== true
        ) {
            throw new InstallationException('Telegram menolak token atau Chat ID. Pastikan bot telah ditambahkan ke tujuan.');
        }
    }

    public function activateDatabase(array $configuration): ConnectionInterface
    {
        config([
            'database.default' => self::CONNECTION_NAME,
            'database.connections.'.self::CONNECTION_NAME => $configuration,
        ]);
        DB::purge(self::CONNECTION_NAME);
        DB::setDefaultConnection(self::CONNECTION_NAME);

        return DB::connection(self::CONNECTION_NAME);
    }
}
