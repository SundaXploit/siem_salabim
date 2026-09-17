<?php

namespace App\Services;

use App\Models\Configuration;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramService
{
    protected Client $client;

    protected string $botToken;

    protected int $maxAttempts;

    /** @var array<int, int> */
    protected array $retryDelaysMs = [400, 1200, 2500];

    public function __construct()
    {
        $this->botToken = trim((string) Configuration::get(
            'telegram_bot_token',
            config('services.telegram.bot_token', ''),
        ));

        $this->maxAttempts = max(1, min(
            4,
            (int) config('services.telegram.max_attempts', 3),
        ));

        $clientOptions = [
            'base_uri' => rtrim((string) config(
                'services.telegram.base_url',
                'https://api.telegram.org',
            ), '/'),
            'connect_timeout' => (float) config('services.telegram.connect_timeout', 5),
            'timeout' => (float) config('services.telegram.timeout', 20),
            'http_errors' => false,
        ];

        // This host has proven IPv4 connectivity to Telegram. Avoid waiting
        // on a short-lived AAAA record when no usable IPv6 route is present.
        if ((bool) config('services.telegram.force_ipv4', true)) {
            $clientOptions['force_ip_resolve'] = 'v4';
        }

        $this->client = new Client($clientOptions);
    }

    /**
     * Send a message to a Telegram chat
     *
     * @return array ['success' => bool, 'status' => int, 'error' => string|null]
     */
    public function sendMessage(string $chatId, string $message, ?string $botToken = null): array
    {
        $token = trim($botToken ?? (string) Configuration::get(
            'telegram_bot_token',
            $this->botToken ?: config('services.telegram.bot_token', ''),
        ));

        if ($token === '') {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Bot token Telegram belum dikonfigurasi.',
                'error_type' => 'missing_token',
            ];
        }

        return $this->requestBotApi($token, 'sendMessage', [
            'json' => [
                'chat_id' => trim($chatId),
                'text' => $message,
                'parse_mode' => 'HTML',
            ],
        ]);
    }

    /**
     * Send a photo file to a Telegram chat.
     * Photo is sent as a separate message from text — NOT as caption.
     *
     * @param  string  $photoPath  Absolute path to the image file on disk
     * @param  string|null  $filename  Optional filename to send to Telegram (useful for temp files)
     * @return array ['success' => bool, 'status' => int, 'error' => string|null]
     */
    public function sendPhoto(string $chatId, string $photoPath, ?string $filename = null): array
    {
        // Resolve the token at call time so long-running workers do not keep a
        // stale value after an administrator rotates it in Settings.
        $token = trim((string) Configuration::get(
            'telegram_bot_token',
            $this->botToken ?: config('services.telegram.bot_token', ''),
        ));

        if ($token === '') {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Bot token Telegram belum dikonfigurasi.',
                'error_type' => 'missing_token',
            ];
        }

        if (! is_file($photoPath) || ! is_readable($photoPath)) {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Berkas bukti tidak ditemukan atau tidak dapat dibaca.',
                'error_type' => 'unreadable_file',
            ];
        }

        $contents = file_get_contents($photoPath);

        if ($contents === false) {
            return [
                'success' => false,
                'status' => 0,
                'error' => 'Berkas bukti tidak dapat dibaca.',
                'error_type' => 'unreadable_file',
            ];
        }

        return $this->requestBotApi($token, 'sendPhoto', [
            'multipart' => [
                ['name' => 'chat_id', 'contents' => trim($chatId)],
                [
                    'name' => 'photo',
                    'contents' => $contents,
                    'filename' => $filename ?: basename($photoPath),
                ],
            ],
        ]);
    }

    /**
     * Perform a Telegram Bot API request with conservative retries.
     *
     * POST requests are retried only when cURL confirms the request never
     * reached a remote host. This avoids duplicate incident notifications
     * when Telegram accepted a request but its response was lost.
     *
     * @param  array<string, mixed>  $options
     * @return array{success: bool, status: int, error: ?string, error_type?: ?string, attempts?: int}
     */
    protected function requestBotApi(string $token, string $method, array $options): array
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->client->post("/bot{$token}/{$method}", $options);
                $status = $response->getStatusCode();
                $body = json_decode((string) $response->getBody(), true);
                $success = is_array($body) && ($body['ok'] ?? false) === true;

                return [
                    'success' => $success,
                    'status' => $status,
                    'error' => $success
                        ? null
                        : self::apiErrorMessage(
                            $status,
                            is_array($body) ? ($body['description'] ?? null) : null,
                        ),
                    'error_type' => $success ? null : self::apiErrorType($status),
                    'attempts' => $attempt,
                ];
            } catch (ConnectException $exception) {
                $context = $exception->getHandlerContext();
                $errno = (int) ($context['errno'] ?? 0);
                $willRetry = $attempt < $this->maxAttempts
                    && self::safeToRetryPost($errno, $context);

                self::logNetworkFailure(
                    $method,
                    $attempt,
                    $this->maxAttempts,
                    $errno,
                    $context,
                    $willRetry,
                );

                if ($willRetry) {
                    $this->pauseBeforeRetry($attempt);

                    continue;
                }

                return [
                    'success' => false,
                    'status' => 0,
                    'error' => self::connectionErrorMessage($errno),
                    'error_type' => self::connectionErrorType($errno),
                    'attempts' => $attempt,
                ];
            } catch (RequestException $exception) {
                $status = $exception->hasResponse()
                    ? $exception->getResponse()->getStatusCode()
                    : 0;

                Log::warning('[Telegram] Bot API request failed', [
                    'operation' => $method,
                    'status' => $status,
                    'exception_class' => $exception::class,
                ]);

                return [
                    'success' => false,
                    'status' => $status,
                    'error' => $status > 0
                        ? self::apiErrorMessage($status)
                        : self::connectionErrorMessage(0),
                    'error_type' => $status > 0
                        ? self::apiErrorType($status)
                        : 'connection_failed',
                    'attempts' => $attempt,
                ];
            } catch (Throwable $exception) {
                Log::warning('[Telegram] Unexpected client failure', [
                    'operation' => $method,
                    'exception_class' => $exception::class,
                ]);

                return [
                    'success' => false,
                    'status' => 0,
                    'error' => 'Klien Telegram mengalami kesalahan internal. Periksa log aplikasi.',
                    'error_type' => 'client_error',
                    'attempts' => $attempt,
                ];
            }
        }

        return [
            'success' => false,
            'status' => 0,
            'error' => self::connectionErrorMessage(0),
            'error_type' => 'connection_failed',
            'attempts' => $this->maxAttempts,
        ];
    }

    /** @param array<string, mixed> $context */
    private static function safeToRetryPost(int $errno, array $context): bool
    {
        if (in_array($errno, [6, 7], true)) {
            return true;
        }

        if ($errno !== 28 && $errno !== 0) {
            return false;
        }

        return blank($context['primary_ip'] ?? null)
            && (float) ($context['connect_time'] ?? 0) <= 0.0
            && (float) ($context['size_upload'] ?? 0) <= 0.0;
    }

    private function pauseBeforeRetry(int $attempt): void
    {
        $delayMs = $this->retryDelaysMs[$attempt - 1]
            ?? end($this->retryDelaysMs)
            ?: 500;

        usleep($delayMs * 1000);
    }

    /** @param array<string, mixed> $context */
    private static function logNetworkFailure(
        string $method,
        int $attempt,
        int $maxAttempts,
        int $errno,
        array $context,
        bool $willRetry,
    ): void {
        Log::warning('[Telegram] Network attempt failed', [
            'operation' => $method,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'curl_errno' => $errno,
            'error_type' => self::connectionErrorType($errno),
            'will_retry' => $willRetry,
            'name_lookup_ms' => (int) round(((float) ($context['namelookup_time'] ?? 0)) * 1000),
            'connect_ms' => (int) round(((float) ($context['connect_time'] ?? 0)) * 1000),
            'primary_ip_available' => filled($context['primary_ip'] ?? null),
        ]);
    }

    private static function connectionErrorType(int $errno): string
    {
        return match ($errno) {
            6 => 'dns_resolution_failed',
            7 => 'tcp_connection_failed',
            28 => 'connection_timeout',
            35, 60 => 'tls_failed',
            default => 'connection_failed',
        };
    }

    private static function connectionErrorMessage(int $errno): string
    {
        return match ($errno) {
            6 => 'DNS gagal menemukan api.telegram.org. Aplikasi sudah mencoba ulang, tetapi resolver masih tidak merespons.',
            7 => 'Alamat Telegram ditemukan, tetapi koneksi TCP ke port 443 gagal.',
            28 => 'Koneksi Telegram melewati batas waktu setelah dicoba ulang.',
            35, 60 => 'Validasi koneksi TLS Telegram gagal. Periksa sertifikat CA dan waktu sistem.',
            default => 'Tidak dapat menghubungi Telegram setelah beberapa percobaan. Periksa DNS dan koneksi internet server aplikasi.',
        };
    }

    private static function apiErrorType(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request',
            401, 404 => 'invalid_token',
            403 => 'chat_access_denied',
            429 => 'rate_limited',
            default => $status >= 500 ? 'telegram_server_error' : 'telegram_api_error',
        };
    }

    private static function apiErrorMessage(int $status, ?string $description = null): string
    {
        $description = strtolower((string) $description);

        if ($status === 400 && str_contains($description, 'chat not found')) {
            return 'Chat ID tidak ditemukan. Pastikan bot sudah ditambahkan ke grup tujuan.';
        }

        return match ($status) {
            400 => 'Permintaan Telegram ditolak. Periksa Chat ID dan format pesan.',
            401, 404 => 'Bot token Telegram tidak valid.',
            403 => 'Bot tidak memiliki akses ke chat tujuan. Tambahkan bot ke grup dan periksa izinnya.',
            429 => 'Telegram membatasi permintaan. Tunggu beberapa saat lalu coba kembali.',
            default => $status >= 500
                ? 'Layanan Telegram sedang mengalami gangguan. Coba kembali beberapa saat lagi.'
                : "Telegram mengembalikan HTTP status {$status}.",
        };
    }

    /**
     * Build a pre-filled notification template from alert data
     */
    public static function buildTemplate(array $alert, string $extraMessage = ''): string
    {
        $rawData = is_array($alert['raw_data']) ? $alert['raw_data'] : [];
        $data = $rawData['data'] ?? [];
        $syscheck = $rawData['syscheck'] ?? [];
        $timestamp = isset($rawData['@timestamp'])
            ? Carbon::parse($rawData['@timestamp'])->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB'
            : ($alert['first_seen_at'] ?? '-');

        $srcIp = $alert['src_ip'] ?? $data['srcip'] ?? '-';
        $dstIp = $alert['dst_ip'] ?? $data['dstip'] ?? '-';
        $agentName = $alert['agent_name'] ?? '-';
        $ruleDesc = $alert['rule_description'] ?? '-';
        $ruleId = $alert['rule_id'] ?? '-';
        $ruleLevel = $alert['rule_level'] ?? '-';
        $path = $syscheck['path'] ?? $data['path'] ?? '-';
        $dstUser = $data['dstuser'] ?? $data['win']['eventdata']['targetUserName'] ?? '-';

        $lines = [
            "🚨 <b>SOC Subang telah mendeteksi adanya {$ruleDesc}</b>",
            '',
            "📌 <b>Rule ID</b>   : {$ruleId} (Level {$ruleLevel})",
            "🖥️ <b>Agent</b>     : {$agentName}",
            "🌐 <b>Source IP</b> : {$srcIp}",
            "🎯 <b>Dest IP</b>   : {$dstIp}",
            "📁 <b>Path</b>      : {$path}",
            "👤 <b>User</b>      : {$dstUser}",
            "🕐 <b>Waktu</b>     : {$timestamp}",
        ];

        if (! empty($extraMessage)) {
            $lines[] = '';
            $lines[] = $extraMessage;
        }

        return implode("\n", $lines);
    }
}
