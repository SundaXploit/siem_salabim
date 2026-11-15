<?php

namespace App\Services;

use App\Exceptions\AiAnalysisException;
use App\Models\Configuration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AiProviderService
{
    public static function isLocal(string $provider): bool
    {
        return in_array($provider, ['ollama', 'lmstudio', 'local'], true);
    }

    public static function defaultFormat(string $provider): string
    {
        return config('ai_providers.'.$provider.'.api_format', 'chat_completions');
    }

    /** Shared validation for Settings, connection tests, and both installer layers. */
    public static function configurationRules(string $provider, bool $requireApiKey = true): array
    {
        return [
            'ai_provider' => ['required', Rule::in(array_keys(config('ai_providers')))],
            'ai_api_format' => ['required', Rule::in(['chat_completions', 'responses', 'anthropic_messages'])],
            'ai_base_url' => ['required', 'url:http,https', 'max:2048',
                ...(self::isLocal($provider) ? [] : ['starts_with:https://'])],
            'ai_model' => ['required', 'string', 'max:255'],
            'ai_api_key' => [Rule::requiredIf($requireApiKey && ! self::isLocal($provider)), 'nullable', 'string', 'max:4096'],
        ];
    }

    public static function modelDiscoveryRules(string $provider, bool $requireApiKey = false): array
    {
        $rules = self::configurationRules($provider, $requireApiKey);
        unset($rules['ai_model']);

        return $rules;
    }

    /** Resolve settings without exposing credentials to Blade or browser code. */
    public function settings(array $overrides = []): array
    {
        $savedProvider = (string) Configuration::get('ai_provider', config('services.ai.provider', 'custom'));
        $savedUrl = rtrim(trim((string) Configuration::get('ai_base_url', config('services.ai.base_url', ''))), '/');
        // Existing saved integrations used Responses before the provider selector existed.
        $savedFormat = Configuration::get('ai_api_format', Configuration::where('key', 'ai_base_url')->exists()
            ? 'responses' : config('services.ai.api_format', self::defaultFormat($savedProvider)));
        $provider = (string) ($overrides['ai_provider'] ?? $savedProvider);
        $url = rtrim(trim((string) ($overrides['ai_base_url'] ?? $savedUrl)), '/');
        $sameDestination = $provider === $savedProvider && $url === $savedUrl;
        $key = filled($overrides['ai_api_key'] ?? null)
            ? trim($overrides['ai_api_key'])
            : ($sameDestination ? trim((string) Configuration::get('ai_api_key', config('services.ai.api_key', ''))) : '');

        return [
            'provider' => $provider,
            'api_format' => $overrides['ai_api_format'] ?? ($provider === $savedProvider ? $savedFormat : self::defaultFormat($provider)),
            'base_url' => $url,
            'model' => trim((string) ($overrides['ai_model'] ?? Configuration::get('ai_model', config('services.ai.model', '')))),
            'api_key' => $key,
        ];
    }

    public function isConfigured(?array $settings = null): bool
    {
        $settings ??= $this->settings();

        return filled($settings['base_url']) && filled($settings['model'])
            && (self::isLocal($settings['provider']) || filled($settings['api_key']));
    }

    public function generate(string $system, string $prompt, int $maxTokens, int $timeout, ?string $model = null, ?string $reasoningEffort = null, ?array $settings = null): string
    {
        $settings ??= $this->settings();
        if (! $this->isConfigured($settings)) {
            throw new AiAnalysisException('Lengkapi endpoint, model, dan API key provider AI. API key boleh kosong untuk LLM lokal.');
        }

        $key = 'ai-provider:'.hash('sha256', $settings['provider'].'|'.$settings['base_url']);
        $slot = null;
        for ($index = 0; $index < max(1, min(20, (int) config('services.ai.max_concurrent_requests', 3))); $index++) {
            $candidate = \Illuminate\Support\Facades\Cache::lock($key.':slot:'.$index, max(30, $timeout + 15));
            if ($candidate->get()) { $slot = $candidate; break; }
        }
        if (! $slot) throw new AiAnalysisException('Kapasitas permintaan AI sedang penuh. Coba kembali setelah analisis lain selesai.');
        try {
            \Illuminate\Support\Facades\Cache::lock($key.':budget', 3)->block(1, function () use ($key): void {
                $maximum = max(1, (int) config('services.ai.requests_per_minute', 20));
                if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, $maximum)) {
                    throw new AiAnalysisException('Batas permintaan provider AI per menit tercapai. Tunggu sebelum mencoba kembali.');
                }
                // An attempted provider request remains counted even if it times out or fails.
                \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
            });

            return $this->generateRequest($system, $prompt, $maxTokens, $timeout, $model, $reasoningEffort, $settings);
        } finally {
            $slot->release();
        }
    }

    private function generateRequest(string $system, string $prompt, int $maxTokens, int $timeout, ?string $model, ?string $reasoningEffort, array $settings): string
    {

        $payload = ['model' => $model ?: $settings['model'], 'stream' => false];
        $messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]];
        $path = match ($settings['api_format']) {
            'responses' => '/responses',
            'anthropic_messages' => '/messages',
            default => '/chat/completions',
        };

        if ($settings['api_format'] === 'responses') {
            $payload['input'] = array_map(static fn ($message) => [
                'role' => $message['role'], 'content' => [['type' => 'input_text', 'text' => $message['content']]],
            ], $messages);
            $payload['max_output_tokens'] = $maxTokens;
            $payload['store'] = false;
            if (filled($reasoningEffort)) {
                $payload['reasoning'] = ['effort' => $reasoningEffort];
            }
        } elseif ($settings['api_format'] === 'anthropic_messages') {
            $payload['system'] = $system;
            $payload['messages'] = [$messages[1]];
            $payload['max_tokens'] = $maxTokens;
        } else {
            $payload['messages'] = $messages;
            $payload[$settings['provider'] === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $maxTokens;
        }

        $request = $this->request($settings, $timeout);

        try {
            $response = $request->post($settings['base_url'].$path, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('[AI] Provider connection failed', ['provider' => $settings['provider'], 'exception_class' => $exception::class]);
            throw new AiAnalysisException('Layanan AI tidak dapat dihubungi atau melewati batas waktu. Periksa endpoint dan jaringan server aplikasi.');
        }

        if (! $response->successful()) {
            Log::warning('[AI] Provider rejected request', ['provider' => $settings['provider'], 'status' => $response->status()]);
            throw new AiAnalysisException(match ($response->status()) {
                401, 403 => 'Layanan AI menolak akses. Periksa API key dan izin model.',
                404 => 'Endpoint atau model AI tidak ditemukan. Periksa base URL, format API, dan nama model.',
                429 => 'Layanan AI sedang membatasi permintaan. Tunggu beberapa saat lalu coba kembali.',
                default => 'Layanan AI menolak permintaan (HTTP '.$response->status().'). Periksa format API dan dukungan model.',
            });
        }

        $data = $response->json();
        if (! is_array($data) || isset($data['error'])) {
            throw new AiAnalysisException('Provider AI tidak mengembalikan respons yang valid. Periksa endpoint dan format API.');
        }

        $text = self::extractText($data, $settings['api_format']);
        if ($text === '') {
            throw new AiAnalysisException('Endpoint AI terhubung, tetapi tidak mengembalikan teks jawaban. Periksa model dan batas token.');
        }

        return $text;
    }

    private function request(array $settings, int $timeout): PendingRequest
    {
        $request = Http::acceptJson()->connectTimeout(5)->timeout($timeout)
            ->withOptions(['allow_redirects' => false]);
        if ($settings['api_format'] === 'anthropic_messages') {
            $request = $request->withHeaders(['anthropic-version' => '2023-06-01']);
            if (filled($settings['api_key'])) {
                $request = $request->withHeaders(['x-api-key' => $settings['api_key']]);
            }
        } elseif (filled($settings['api_key'])) {
            $request = $request->withToken($settings['api_key']);
        }

        return $request;
    }

    /** Read the endpoint catalogue without generating text or requiring a model name. */
    public function models(array $settings): array
    {
        if (blank($settings['base_url']) || (! self::isLocal($settings['provider']) && blank($settings['api_key']))) {
            throw new AiAnalysisException('Isi endpoint dan API key untuk tujuan ini. LLM lokal boleh tanpa API key.');
        }

        $models = [];
        $query = $settings['api_format'] === 'anthropic_messages' ? ['limit' => 1000] : [];
        $hasMore = false;
        $truncated = false;
        $cursor = null;
        // ponytail: bound catalogue reads to 3 pages/1000 models; expose truncation for manual entry.
        for ($page = 0; $page < 3; $page++) {
            try {
                $response = $this->request($settings, 10)->get(rtrim($settings['base_url'], '/').'/models', $query);
            } catch (ConnectionException) {
                throw new AiAnalysisException('Daftar model tidak dapat diambil. Periksa endpoint dan jaringan server, atau isi model secara manual.');
            }

            if (! $response->successful()) {
                throw new AiAnalysisException(match ($response->status()) {
                    401, 403 => 'Akses daftar model ditolak. Periksa API key dan izin endpoint.',
                    404, 405, 501 => 'Endpoint tidak menyediakan daftar model melalui /models. Isi nama model secara manual.',
                    429 => 'Endpoint membatasi permintaan daftar model. Tunggu sebentar lalu coba lagi.',
                    default => 'Daftar model belum dapat diambil (HTTP '.$response->status().'). Model tetap dapat diisi manual.',
                });
            }

            $body = $response->json();
            $items = is_array($body) ? ($body['data'] ?? $body['models'] ?? null) : null;
            if (! is_array($items) || ! array_is_list($items) || isset($body['error'])) {
                throw new AiAnalysisException('Format daftar model tidak dikenali. Isi nama model secara manual.');
            }

            foreach ($items as $position => $item) {
                $id = is_string($item) ? $item : (is_array($item) ? ($item['id'] ?? $item['model'] ?? $item['name'] ?? null) : null);
                if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 255) {
                    continue;
                }
                $id = trim($id);
                $name = is_array($item) ? ($item['display_name'] ?? $item['name'] ?? $id) : $id;
                $models[$id] = ['id' => $id, 'name' => is_string($name) ? mb_substr($name, 0, 255) : $id];
                if (count($models) >= 1000) {
                    $truncated = $position + 1 < count($items);
                    break;
                }
            }

            $hasMore = ($body['has_more'] ?? false) === true;
            $nextCursor = $body['last_id'] ?? null;
            if (! $hasMore || count($models) >= 1000 || ! is_string($nextCursor) || $nextCursor === '' || $nextCursor === $cursor) {
                break;
            }
            $query['after_id'] = $cursor = $nextCursor;
        }

        $models = array_values($models);
        usort($models, fn ($left, $right) => strnatcasecmp($left['id'], $right['id']));

        return ['models' => $models, 'truncated' => $hasMore || $truncated];
    }

    private static function extractText(array $data, string $format): string
    {
        $content = match ($format) {
            'anthropic_messages' => $data['content'] ?? [],
            'responses' => filled($data['output_text'] ?? null) ? $data['output_text'] : collect($data['output'] ?? [])
                ->filter(fn ($item) => ($item['type'] ?? 'message') === 'message')
                ->flatMap(fn ($item) => $item['content'] ?? [])->all(),
            default => data_get($data, 'choices.0.message.content', ''),
        };
        if (is_array($content)) {
            $content = collect($content)->map(static fn ($part) => is_string($part) ? $part
                : (in_array($part['type'] ?? 'text', ['text', 'output_text'], true) ? ($part['text'] ?? '') : ''))->implode('');
        }

        return is_string($content) ? trim($content) : '';
    }
}
