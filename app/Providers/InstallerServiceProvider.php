<?php

namespace App\Providers;

use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationConnectionTester;
use App\Services\Installation\InstallationState;
use App\Services\Installation\Installer;
use Illuminate\Support\ServiceProvider;

class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnvironmentFileWriter::class);
        $this->app->singleton(InstallationState::class);
        $this->app->singleton(InstallationConnectionTester::class);
        $this->app->singleton(Installer::class);

        /** @var EnvironmentFileWriter $environment */
        $environment = $this->app->make(EnvironmentFileWriter::class);
        /** @var InstallationState $state */
        $state = $this->app->make(InstallationState::class);

        // Never generate or replace an APP_KEY for an installation which is
        // already in service. Besides breaking encrypted configuration, doing
        // so would make every normal request require write access to the code
        // directory. Legacy-admin detection and the private marker both work
        // before a database-backed session/cache is resolved.
        if (! $state->installationRequired()) {
            return;
        }

        $runtimeKey = config('app.key');
        $fileKey = $environment->value('APP_KEY');

        if (blank($fileKey)) {
            $prepared = filled($runtimeKey)
                ? $environment->prepare(['APP_KEY' => (string) $runtimeKey])
                : $environment->ensureEnvironmentAndKey();

            // Respect an APP_KEY supplied by the process environment.
            // Otherwise, make the newly-generated key available because
            // dotenv loading has already completed for this request.
            if (blank($runtimeKey) && filled($prepared['app_key'] ?? null)) {
                config(['app.key' => $prepared['app_key']]);
            }
        } elseif (blank($runtimeKey)) {
            // This can happen when an old configuration cache was generated
            // before the installer prepared .env.
            config(['app.key' => $fileKey]);
        }

        // Fresh installations have no database tables yet. These stores must
        // remain filesystem-backed until migrations are complete.
        config([
            // Never expose a debug exception page while the public installer
            // is pending, including before the preparation command has had a
            // chance to normalize an older .env file.
            'app.env' => 'production',
            'app.debug' => false,
            'session.driver' => 'file',
            'session.encrypt' => true,
            'cache.default' => 'file',
            'queue.default' => 'sync',
        ]);
    }
}
