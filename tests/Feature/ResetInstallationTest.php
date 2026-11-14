<?php

namespace Tests\Feature;

use App\Services\Installation\EnvironmentFileWriter;
use App\Services\Installation\InstallationState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetInstallationTest extends TestCase
{
    private string $directory;

    private EnvironmentFileWriter $environment;

    private InstallationState $state;

    private string $oldToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-reset-test-'.bin2hex(random_bytes(8));
        foreach (['storage/framework/cache/data', 'storage/framework/views', 'bootstrap/cache'] as $path) {
            mkdir($this->directory.'/'.$path, 0700, true);
        }
        touch($this->directory.'/database.sqlite');
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\nAPP_INSTALLED=false\n");

        // All destructive operations, including cache deletion, stay inside
        // this test's disposable directory and its dedicated SQLite database.
        $this->app->useStoragePath($this->directory.'/storage');
        $this->app->useBootstrapPath($this->directory.'/bootstrap');
        config([
            'database.default' => 'reset_test',
            'database.connections.reset_test' => [
                'driver' => 'sqlite',
                'database' => $this->directory.'/database.sqlite',
                'foreign_key_constraints' => true,
            ],
            'installer.installed' => true,
            'installer.marker_path' => $this->directory.'/private/installed.json',
            'installer.token_path' => $this->directory.'/private/token.json',
            'application_updates.runtime_marker' => $this->directory.'/update.lock',
            'cache.default' => 'array',
            'cache.stores.file.path' => $this->directory.'/storage/framework/cache/data',
            'cache.stores.file.lock_path' => $this->directory.'/storage/framework/cache/data',
            'view.compiled' => $this->directory.'/storage/framework/views',
            'session.driver' => 'array',
        ]);
        DB::setDefaultConnection('reset_test');

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('role');
        });
        DB::table('users')->insert(['role' => 'admin']);
        foreach (['alerts', 'configurations', 'jobs', 'migrations'] as $tableName) {
            $schema->create($tableName, fn (Blueprint $table) => $table->id());
            DB::table($tableName)->insert(['id' => 1]);
        }
        DB::statement('CREATE VIEW reset_test_view AS SELECT id FROM users');

        $this->environment = new EnvironmentFileWriter($this->directory);
        $this->environment->prepare([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->directory.'/database.sqlite',
            'DB_PASSWORD' => 'test-database-password',
            'TELEGRAM_BOT_TOKEN' => 'test-telegram-token',
            'OPENSEARCH_PASSWORD' => 'test-opensearch-secret',
            'AI_API_KEY' => 'test-ai-secret',
            'AMANAI_API_KEY' => 'test-legacy-secret',
            'MAIL_PASSWORD' => 'test-mail-secret',
            'SESSION_COOKIE' => 'old-session-cookie',
            'CACHE_PREFIX' => 'old-cache-prefix',
        ]);
        $this->state = new InstallationState(environment: $this->environment);
        $this->state->markInstalled();
        $this->oldToken = $this->state->issueInstallerToken();
        $this->app->instance(EnvironmentFileWriter::class, $this->environment);
        $this->app->instance(InstallationState::class, $this->state);
    }

    protected function tearDown(): void
    {
        DB::purge('reset_test');
        // Verify the deletion root is exactly the generated private temp folder.
        $resolved = realpath($this->directory);
        $temporaryRoot = realpath(sys_get_temp_dir());
        if ($resolved !== false && dirname($resolved) === $temporaryRoot
            && str_starts_with(basename($resolved), 'siem-reset-test-')) {
            (new Filesystem)->deleteDirectory($resolved);
        }

        parent::tearDown();
    }

    public function test_declining_confirmation_keeps_database_environment_and_installer_lock(): void
    {
        $originalEnvironment = file_get_contents($this->environment->path());

        $this->artisan('siem:reset-installation')
            ->expectsConfirmation('Hapus semua data dan ulangi instalasi?', 'no')
            ->expectsOutput('Reset dibatalkan.')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame($originalEnvironment, file_get_contents($this->environment->path()));
        $this->assertTrue($this->state->isInstalled());
        $this->assertTrue($this->state->verifyToken($this->oldToken));
    }

    public function test_reset_empties_database_and_opens_installer_with_a_new_token(): void
    {
        $key = $this->environment->value('APP_KEY');
        $dbPath = $this->environment->value('DB_DATABASE');
        $fileCache = app('cache')->store('file');
        $fileCache->put('reset-test-old-key', 'stale application data', 300);
        $cachePath = $fileCache->getStore()->path('reset-test-old-key');
        file_put_contents($this->directory.'/storage/framework/views/old.php', '<?php // old view');
        file_put_contents($this->directory.'/bootstrap/cache/config.php', '<?php return [];');
        file_put_contents($this->directory.'/bootstrap/cache/routes-v7.php', '<?php // old routes');
        mkdir($this->directory.'/storage/app/private/siem-exports', 0700, true);
        $archive = $this->directory.'/storage/app/private/siem-exports/keep.sql';
        file_put_contents($archive, 'preserved backup');

        $this->assertSame(0, Artisan::call('siem:reset-installation', ['--force' => true]));

        $this->assertSame([], DB::connection()->getSchemaBuilder()->getTableListing());
        $this->assertSame([], DB::connection()->getSchemaBuilder()->getViews());
        $this->assertFalse($this->state->isInstalled());
        $this->assertFileDoesNotExist($this->directory.'/private/installed.json');
        $this->assertSame('false', $this->environment->value('APP_INSTALLED'));
        $this->assertSame($key, $this->environment->value('APP_KEY'));
        $this->assertSame($dbPath, $this->environment->value('DB_DATABASE'));
        $this->assertSame('test-database-password', $this->environment->value('DB_PASSWORD'));
        foreach (['TELEGRAM_BOT_TOKEN', 'OPENSEARCH_PASSWORD', 'AI_API_KEY', 'AMANAI_API_KEY'] as $name) {
            $this->assertSame('', $this->environment->value($name));
        }
        $this->assertSame('log', $this->environment->value('MAIL_MAILER'));
        $this->assertSame('production', $this->environment->value('APP_ENV'));
        $this->assertSame('false', $this->environment->value('APP_DEBUG'));
        $this->assertNotSame('old-session-cookie', $this->environment->value('SESSION_COOKIE'));
        $this->assertNotSame('old-cache-prefix', $this->environment->value('CACHE_PREFIX'));
        $this->assertFileDoesNotExist($cachePath);
        $this->assertFileDoesNotExist($this->directory.'/storage/framework/views/old.php');
        $this->assertFileDoesNotExist($this->directory.'/bootstrap/cache/config.php');
        $this->assertFileDoesNotExist($this->directory.'/bootstrap/cache/routes-v7.php');
        $this->assertSame('preserved backup', file_get_contents($archive));

        $output = Artisan::output();
        $this->assertSame(1, preg_match('/^([A-Za-z0-9_-]{43})\r?$/m', $output, $matches));
        $this->assertFalse($this->state->verifyToken($this->oldToken));
        $this->assertTrue($this->state->verifyToken($matches[1]));
        $this->get('/install')->assertOk()->assertSee('siem:prepare-installation', false);
        $this->post('/install/unlock', ['installation_token' => $matches[1]])
            ->assertRedirect(route('install.index'))
            ->assertSessionHas('installer.unlocked', true);
    }

    public function test_connection_failure_preserves_installed_status_and_does_not_leak_secrets(): void
    {
        DB::purge('reset_test');
        config(['database.connections.reset_test.database' => $this->directory.'/missing-secret-database.sqlite']);

        $this->assertSame(1, Artisan::call('siem:reset-installation', ['--force' => true]));

        $this->assertTrue($this->state->isInstalled());
        $this->assertTrue($this->state->verifyToken($this->oldToken));
        $this->assertSame('true', $this->environment->value('APP_INSTALLED'));
        $this->assertSame('test-ai-secret', $this->environment->value('AI_API_KEY'));
        $this->assertStringContainsString('Reset gagal sebelum penghapusan database', Artisan::output());
        $this->assertStringNotContainsString('test-database-password', Artisan::output());
        $this->assertStringNotContainsString('SQLiteDatabaseDoesNotExistException', Artisan::output());
    }

    public function test_reset_refuses_to_run_while_installation_or_update_is_active(): void
    {
        $lock = fopen($this->directory.'/storage/framework/siem-installation.lock', 'c+');
        flock($lock, LOCK_EX | LOCK_NB);
        try {
            $this->assertSame(1, Artisan::call('siem:reset-installation', ['--force' => true]));
            $this->assertStringContainsString('proses instalasi atau reset lain', Artisan::output());
            $this->assertSame(1, DB::table('users')->count());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        file_put_contents($this->directory.'/update.lock', 'update in progress');
        $this->assertSame(1, Artisan::call('siem:reset-installation', ['--force' => true]));
        $this->assertStringContainsString('update aplikasi atau pemulihannya masih aktif', Artisan::output());
        $this->assertTrue($this->state->isInstalled());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_database_wipe_failure_keeps_installer_closed_for_recovery(): void
    {
        DB::statement('PRAGMA query_only = ON');
        try {
            $this->assertSame(1, Artisan::call('siem:reset-installation', ['--force' => true]));
            $this->assertStringContainsString('Reset belum selesai', Artisan::output());
            $this->assertTrue($this->state->isInstalled());
            $this->assertTrue($this->state->verifyToken($this->oldToken));
            $this->assertSame('true', $this->environment->value('APP_INSTALLED'));
            $this->assertSame(1, DB::table('users')->count());
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
    }

    public function test_reset_can_be_repeated_on_an_already_empty_database(): void
    {
        $this->assertSame(0, Artisan::call('siem:reset-installation', ['--force' => true]));
        $firstCookie = $this->environment->value('SESSION_COOKIE');

        $this->assertSame(0, Artisan::call('siem:reset-installation', ['--force' => true]));
        $this->assertFalse($this->state->isInstalled());
        $this->assertNotSame($firstCookie, $this->environment->value('SESSION_COOKIE'));
        $this->assertSame([], DB::connection()->getSchemaBuilder()->getTableListing());
    }
}
