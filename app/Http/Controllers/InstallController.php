<?php

namespace App\Http\Controllers;

use App\Http\Requests\Installation\InstallApplicationRequest;
use App\Http\Requests\Installation\UnlockInstallerRequest;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class InstallController extends Controller
{
    private const SESSION_UNLOCKED = 'installer.unlocked';

    private const SESSION_UNLOCKED_AT = 'installer.unlocked_at';

    private const UNLOCK_TTL_MINUTES = 30;

    public function index(Request $request): Response
    {
        $unlocked = $this->isUnlocked($request);

        return $this->noStore(response()->view('install.index', [
            'unlocked' => $unlocked,
            'defaults' => [
                'app_name' => 'SIEM Salabim',
                'app_url' => $request->getSchemeAndHttpHost(),
                'app_timezone' => 'Asia/Jakarta',
                'db_connection' => 'pgsql',
                'db_host' => '127.0.0.1',
                'db_port' => '5432',
                'opensearch_host' => 'https://127.0.0.1:9200',
                'opensearch_index' => 'wazuh-alerts-*',
                'opensearch_source_cluster' => 'wazuh-primary',
                'ai_base_url' => 'https://api.amanai.dev/v1',
                'ai_model' => 'amanai/deepseek-v4.1-flash',
                'mail_port' => '587',
            ],
        ]));
    }

    public function unlock(
        UnlockInstallerRequest $request,
        InstallationState $installationState,
    ): RedirectResponse {
        $token = (string) $request->validated('installation_token');
        $request->forgetSensitiveInput();

        if (! $installationState->consumeInstallerToken($token)) {
            return $this->noStore(
                redirect()
                    ->route('install.index')
                    ->withErrors(['installation_token' => 'Token instalasi tidak valid atau sudah kedaluwarsa.']),
            );
        }

        $request->session()->regenerate();
        $request->session()->put([
            self::SESSION_UNLOCKED => true,
            self::SESSION_UNLOCKED_AT => now()->timestamp,
        ]);

        return $this->noStore(redirect()->route('install.index'));
    }

    public function store(
        InstallApplicationRequest $request,
        Installer $installer,
    ): RedirectResponse {
        if (! $this->isUnlocked($request)) {
            return $this->noStore(
                redirect()
                    ->route('install.index')
                    ->withErrors(['installation_token' => 'Sesi instalasi berakhir. Masukkan token instalasi kembali.']),
            );
        }

        try {
            $installer->install($request->validated());
        } catch (ValidationException $exception) {
            $request->forgetSensitiveInput();

            throw $exception;
        } catch (Throwable $exception) {
            // Never pass exception messages or traces to the installer log: a
            // connector may include credentials in its connection exception.
            Log::error('Instalasi aplikasi gagal.', [
                'exception_type' => $exception::class,
            ]);

            $safeInput = $request->safe()->except([
                'db_password',
                'opensearch_password',
                'telegram_bot_token',
                'ai_api_key',
                'mail_password',
                'admin_password',
            ]);
            $request->forgetSensitiveInput();

            return $this->noStore(
                redirect()
                    ->route('install.index')
                    ->withInput($safeInput)
                    ->withErrors([
                        'installation' => 'Instalasi belum berhasil. Periksa koneksi dan konfigurasi, lalu coba kembali.',
                    ]),
            );
        }

        $request->session()->forget([
            self::SESSION_UNLOCKED,
            self::SESSION_UNLOCKED_AT,
        ]);
        $request->session()->regenerate();

        return $this->noStore(redirect()->route('install.complete'));
    }

    public function complete(): Response
    {
        return $this->noStore(response()->view('install.complete'));
    }

    private function isUnlocked(Request $request): bool
    {
        if ($request->session()->get(self::SESSION_UNLOCKED) !== true) {
            return false;
        }

        $unlockedAt = (int) $request->session()->get(self::SESSION_UNLOCKED_AT, 0);
        $stillValid = $unlockedAt > 0
            && $unlockedAt >= now()->subMinutes(self::UNLOCK_TTL_MINUTES)->timestamp;

        if (! $stillValid) {
            $request->session()->forget([
                self::SESSION_UNLOCKED,
                self::SESSION_UNLOCKED_AT,
            ]);
        }

        return $stillValid;
    }

    /**
     * @template TResponse of \Symfony\Component\HttpFoundation\Response
     *
     * @param  TResponse  $response
     * @return TResponse
     */
    private function noStore($response)
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
