<?php

namespace App\Console\Commands;

use App\Services\ApplicationUpdateRuntimeGuard;
use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ResetInstallation extends Command
{
    protected $signature = 'siem:reset-installation
                            {--force : Hapus data tanpa meminta konfirmasi}';

    protected $description = 'Hapus seluruh tabel database aplikasi dan buka kembali wizard instalasi';

    public function handle(EnvironmentFileWriter $environment, InstallationState $state): int
    {
        $connection = DB::connection();
        $this->warn('RESET INSTALASI: seluruh tabel pada database aktif akan dihapus, termasuk akun, alert, konfigurasi, dan antrean database.');
        $this->line('Target: '.$connection->getDriverName().' / '.$connection->getDatabaseName());
        $this->warn('Backup database terlebih dahulu. Hentikan worker, scheduler, dan akses pengguna selama reset.');

        if (! $this->option('force') && ! $this->confirm('Hapus semua data dan ulangi instalasi?', false)) {
            $this->info('Reset dibatalkan.');

            return self::SUCCESS;
        }

        $lock = null;
        $databaseResetStarted = false;

        try {
            $path = storage_path('framework/siem-installation.lock');
            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0750, true) && ! is_dir(dirname($path))) {
                throw new RuntimeException('Direktori lock tidak dapat dibuat.');
            }

            $lock = @fopen($path, 'c+');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->error('Reset ditolak: proses instalasi atau reset lain sedang berjalan.');

                return self::FAILURE;
            }

            if (app(ApplicationUpdateRuntimeGuard::class)->isActive()) {
                $this->error('Reset ditolak: update aplikasi atau pemulihannya masih aktif.');

                return self::FAILURE;
            }

            // Check connectivity and .env write access before deleting any tables.
            $connection->getPdo();
            $environment->prepare();
            if (! is_writable(dirname($environment->path()))
                || (is_file($environment->path()) && ! is_writable($environment->path()))) {
                throw new RuntimeException('Berkas .env tidak dapat diperbarui.');
            }

            // Remove the cached installed flag before touching the DB.
            // A new HTTP request still sees APP_INSTALLED=true until reset succeeds.
            if ($this->callSilent('config:clear') !== self::SUCCESS || app()->configurationIsCached()) {
                throw new RuntimeException('Cache konfigurasi tidak dapat dihapus.');
            }

            $databaseResetStarted = true;
            if ($this->callSilent('db:wipe', [
                '--database' => $connection->getName(),
                '--drop-views' => true,
                '--drop-types' => $connection->getDriverName() === 'pgsql',
                '--force' => true,
            ]) !== self::SUCCESS) {
                throw new RuntimeException('Database tidak dapat dikosongkan.');
            }

            // Keep APP_KEY and DB_* so encrypted backups remain recoverable and
            // the same empty database can be used by the next installation.
            $environment->update([
                'SESSION_COOKIE' => 'siem_salabim_session_'.bin2hex(random_bytes(8)),
                'CACHE_PREFIX' => 'siem_salabim_'.bin2hex(random_bytes(8)).'_',
                'OPENSEARCH_HOST' => '',
                'OPENSEARCH_USERNAME' => '',
                'OPENSEARCH_PASSWORD' => '',
                'OPENSEARCH_INDEX' => 'wazuh-alerts-*',
                'OPENSEARCH_SOURCE_CLUSTER' => 'wazuh-primary',
                'TELEGRAM_BOT_TOKEN' => '',
                'TELEGRAM_CHAT_IDS' => '[]',
                'AI_PROVIDER' => 'custom',
                'AI_API_FORMAT' => 'chat_completions',
                'AI_BASE_URL' => '',
                'AI_API_KEY' => '',
                'AI_MODEL' => '',
                'AI_ALERT_MODEL' => '',
                'AMANAI_BASE_URL' => '',
                'AMANAI_API_KEY' => '',
                'AMANAI_MODEL' => '',
                'AMANAI_ALERT_MODEL' => '',
                'MAIL_MAILER' => 'log',
                'MAIL_URL' => null,
                'MAIL_SCHEME' => null,
                'MAIL_HOST' => '127.0.0.1',
                'MAIL_PORT' => '2525',
                'MAIL_USERNAME' => null,
                'MAIL_PASSWORD' => null,
                'MAIL_FROM_ADDRESS' => 'hello@example.com',
                'MAIL_FROM_NAME' => 'SIEM Salabim',
            ]);

            // Never flush a shared Redis/Memcached store. Remote cache keys use
            // the new prefix; the application's local file cache can be cleared.
            foreach (['cache:clear' => ['store' => 'file'], 'route:clear' => [], 'view:clear' => []] as $command => $arguments) {
                if ($this->callSilent($command, $arguments) !== self::SUCCESS) {
                    throw new RuntimeException('Cache aplikasi tidak dapat dibersihkan.');
                }
            }

            $state->reset();
            config(['installer.installed' => false]);
            $this->info('Reset instalasi selesai. Database kosong; akun dan integrasi perlu dikonfigurasi ulang.');

            return $this->call('siem:prepare-installation');
        } catch (Throwable $exception) {
            Log::error('[Installer] reset failed', ['exception_class' => $exception::class]);
            $this->error($databaseResetStarted
                ? 'Reset belum selesai dan database mungkin sudah terhapus sebagian. Periksa koneksi serta izin .env, storage, dan bootstrap/cache, lalu ulangi perintah ini.'
                : 'Reset gagal sebelum penghapusan database. Periksa koneksi database serta izin .env, storage, dan bootstrap/cache.');

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
