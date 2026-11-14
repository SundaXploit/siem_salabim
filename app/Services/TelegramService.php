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
        // Installer checks run before the configurations table exists.
        // Saved credentials are resolved by sendMessage/sendPhoto at call time.
        $this->botToken = trim((string) config('services.telegram.bot_token', ''));

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

    /** Check credentials and chat access without sending any messages. */
    public function testConfiguration(string $token, array $chatIds): array
    {
        $result = $this->requestBotApi(trim($token), 'getMe', []);
        if (! $result['success']) {
            return $result;
        }

        foreach ($chatIds as $chatId) {
            $result = $this->requestBotApi(trim($token), 'getChat', [
                'json' => ['chat_id' => trim((string) $chatId)],
            ]);
            if (! $result['success']) {
                return $result;
            }
        }

        return $result;
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
        $formatFallback = false;
        $sendsMessage = in_array($method, ['sendMessage', 'sendPhoto'], true);
        $dnsFallbackIndex = 0;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $response = $this->client->post("/bot{$token}/{$method}", $options);
                $status = $response->getStatusCode();
                $body = json_decode((string) $response->getBody(), true);
                $success = $status >= 200 && $status < 300
                    && is_array($body) && ($body['ok'] ?? false) === true;

                if ($success) {
                    if (defined('CURLOPT_DOH_URL') && isset($options['curl'][CURLOPT_DOH_URL])) {
                        Log::info('[Telegram] Connected using HTTPS DNS fallback', ['operation' => $method, 'attempt' => $attempt]);
                    }

                    return [
                        'success' => true, 'status' => $status, 'error' => null,
                        'error_type' => null, 'attempts' => $attempt, 'format_fallback' => $formatFallback,
                    ];
                }

                $rejected = is_array($body) && ($body['ok'] ?? null) === false;
                $apiCode = $rejected ? (int) ($body['error_code'] ?? 0) : 0;
                $status = $apiCode >= 400 && $apiCode <= 599 ? $apiCode : ($status >= 400 ? $status : 502);
                $description = is_string($body['description'] ?? null) ? $body['description'] : '';
                $retryAfter = max(0, (int) ($body['parameters']['retry_after'] ?? 0));

                // Explicit rejection guarantees this attempt did not deliver a message.
                if ($rejected && $status === 400 && $method === 'sendMessage'
                    && isset($options['json']['parse_mode'])
                    && preg_match('/parse entities|unsupported start tag|can.t find end tag/i', $description)
                    && $attempt < $this->maxAttempts
                ) {
                    unset($options['json']['parse_mode']);
                    $formatFallback = true;

                    continue;
                }

                if ($rejected && $status === 429 && $retryAfter > 0 && $retryAfter <= 2
                    && $attempt < $this->maxAttempts
                ) {
                    usleep($retryAfter * 1000000);

                    continue;
                }

                Log::warning('[Telegram] Bot API rejected or did not confirm request', [
                    'operation' => $method, 'status' => $status, 'attempt' => $attempt,
                    'error_type' => self::apiErrorType($status), 'retry_after' => $retryAfter,
                ]);

                return [
                    'success' => false,
                    'status' => $status,
                    'error' => $sendsMessage && ! $rejected && $status >= 500
                        ? 'Telegram belum mengonfirmasi pengiriman. Periksa chat tujuan sebelum mengirim ulang agar pesan tidak ganda.'
                        : ($status === 429 && $retryAfter > 0
                            ? "Telegram membatasi permintaan. Tunggu {$retryAfter} detik sebelum mencoba kembali."
                            : self::apiErrorMessage($status, $description)),
                    'error_type' => self::apiErrorType($status),
                    'retry_after' => $retryAfter,
                    'delivery_unknown' => $sendsMessage && ! $rejected && $status >= 500,
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
                    if ($errno === 6 || ($errno === 28 && blank($context['primary_ip'] ?? null))) {
                        $options = $this->withDnsFallback($options, $dnsFallbackIndex++);
                    }
                    $this->pauseBeforeRetry($attempt);

                    continue;
                }

                return [
                    'success' => false,
                    'status' => 0,
                    'error' => $sendsMessage && ! self::safeToRetryPost($errno, $context) && in_array($errno, [0, 28], true)
                        ? 'Respons Telegram terputus setelah koneksi dimulai. Periksa chat tujuan sebelum mengirim ulang agar pesan tidak ganda.'
                        : self::connectionErrorMessage($errno),
                    'error_type' => self::connectionErrorType($errno),
                    'delivery_unknown' => $sendsMessage && ! self::safeToRetryPost($errno, $context) && in_array($errno, [0, 28], true),
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

    /** Use encrypted DNS only after a confirmed lookup failure, without changing the API hostname. */
    private function withDnsFallback(array $options, int $index): array
    {
        if (! config('services.telegram.dns_fallback', true)
            || ! defined('CURLOPT_DOH_URL')
            || ! defined('CURLOPT_DOH_SSL_VERIFYHOST')
            || ! defined('CURLOPT_DOH_SSL_VERIFYPEER')
        ) {
            return $options;
        }

        $base = (string) $this->client->getConfig('base_uri');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https'
            || strtolower((string) parse_url($base, PHP_URL_HOST)) !== 'api.telegram.org'
        ) {
            return $options;
        }

        $resolvers = config('services.telegram.doh_resolvers', ['https://1.1.1.1/dns-query', 'https://1.0.0.1/dns-query']);
        $resolver = $resolvers[$index] ?? null;
        if (! is_string($resolver) || ! filter_var($resolver, FILTER_VALIDATE_URL)
            || parse_url($resolver, PHP_URL_SCHEME) !== 'https'
            || parse_url($resolver, PHP_URL_USER) !== null || parse_url($resolver, PHP_URL_PASS) !== null
        ) {
            return $options;
        }

        $options['curl'][CURLOPT_DOH_URL] = $resolver;
        $options['curl'][CURLOPT_DOH_SSL_VERIFYPEER] = true;
        $options['curl'][CURLOPT_DOH_SSL_VERIFYHOST] = 2;

        return $options;
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
            28 => 'Koneksi Telegram melewati batas waktu. Periksa jaringan server lalu coba kembali.',
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
            return 'Chat ID tidak ditemukan. Periksa ID tujuan, tambahkan bot ke grup/channel, atau kirim /start ke bot untuk chat pribadi.';
        }
        if ($status === 400 && str_contains($description, 'too long')) {
            return 'Pesan terlalu panjang untuk Telegram. Ringkas pesan menjadi maksimal 4096 karakter.';
        }
        if ($status === 400 && str_contains($description, 'migrated')) {
            return 'Grup telah berubah menjadi supergroup. Perbarui Chat ID tujuan di Pengaturan.';
        }

        return match ($status) {
            400 => 'Permintaan Telegram ditolak. Periksa Chat ID dan format pesan.',
            401, 404 => 'Bot token Telegram tidak valid.',
            403 => 'Bot tidak memiliki akses ke chat tujuan. Pastikan bot tidak diblokir, kirim /start untuk chat pribadi, dan periksa izin kirim di grup/channel.',
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
        $rawData = is_array($alert['raw_data'] ?? null) ? $alert['raw_data'] : [];
        $data = $rawData['data'] ?? [];
        $syscheck = $rawData['syscheck'] ?? [];
        $timestamp = $alert['first_seen_at'] ?? '-';
        try {
            if (isset($rawData['@timestamp'])) {
                $timestamp = Carbon::parse($rawData['@timestamp'])->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB';
            }
        } catch (Throwable) {
            // Invalid source timestamps must not prevent notification composition.
        }

        $srcIp = $alert['src_ip'] ?? $data['srcip'] ?? '-';
        $dstIp = $alert['dst_ip'] ?? $data['dstip'] ?? '-';
        $agentName = $alert['agent_name'] ?? '-';
        $ruleDesc = $alert['rule_description'] ?? '-';
        $ruleId = $alert['rule_id'] ?? '-';
        $ruleLevel = $alert['rule_level'] ?? '-';
        $path = $syscheck['path'] ?? $data['path'] ?? '-';
        $dstUser = $data['dstuser'] ?? $data['win']['eventdata']['targetUserName'] ?? '-';

        [$srcIp, $dstIp, $agentName, $ruleDesc, $ruleId, $ruleLevel, $path, $dstUser, $timestamp] = array_map(
            static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            [$srcIp, $dstIp, $agentName, $ruleDesc, $ruleId, $ruleLevel, $path, $dstUser, $timestamp],
        );

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
            $lines[] = htmlspecialchars($extraMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return implode("\n", $lines);
    }
}
