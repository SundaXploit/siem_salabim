<?php

namespace App\Console\Commands;

use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use Illuminate\Console\Command;

class PrepareInstallation extends Command
{
    protected $signature = 'siem:prepare-installation
                            {--ttl= : Masa berlaku token dalam detik (minimal 300)}';

    protected $description = 'Siapkan .env, APP_KEY, dan token sekali pakai untuk membuka wizard installer';

    public function handle(EnvironmentFileWriter $environment, InstallationState $state): int
    {
        if ($state->isInstalled()) {
            $this->error('Aplikasi sudah terpasang. Wizard installer tetap ditutup.');

            return self::FAILURE;
        }

        $ttl = $this->option('ttl');

        if ($ttl !== null && (filter_var($ttl, FILTER_VALIDATE_INT) === false || (int) $ttl < 300)) {
            $this->error('Nilai --ttl harus berupa bilangan bulat minimal 300 detik.');

            return self::INVALID;
        }

        // The browser-facing bootstrap must be fail-safe even when .env was
        // copied from an older development template. The completed wizard
        // keeps these production settings and derives secure-cookie behavior
        // from the administrator-approved APP_URL.
        $result = $environment->prepare([
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'SESSION_ENCRYPT' => true,
        ]);
        $token = $state->issueInstallerToken($ttl === null ? null : (int) $ttl);

        if ($result['environment_created']) {
            $this->info('Berkas .env dibuat dari .env.example.');
        }

        if ($result['key_created']) {
            $this->info('APP_KEY baru dibuat. Simpan backup .env secara aman dan jangan mengganti key tersebut.');
        }

        $this->newLine();
        $this->warn('Token berikut hanya ditampilkan oleh perintah ini. Jangan kirim melalui chat atau log:');
        $this->line($token);
        $this->newLine();
        $this->info('Buka /install, masukkan token, lalu selesaikan wizard sebelum token kedaluwarsa.');

        return self::SUCCESS;
    }
}
