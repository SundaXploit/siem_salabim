<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstallerDevelopmentServerTest extends TestCase
{
    public function test_installer_environment_changes_do_not_interrupt_an_active_http_request(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-install-server-'.bin2hex(random_bytes(8));
        foreach (['public', 'bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/logs'] as $path) {
            mkdir($directory.'/'.$path, 0700, true);
        }
        file_put_contents($directory.'/.env', implode("\n", [
            'APP_NAME=Before', 'APP_INSTALLED=true', 'APP_ENV=testing',
            'APP_KEY=base64:'.base64_encode(str_repeat('t', 32)),
            'CACHE_STORE=array', 'SESSION_DRIVER=array', 'QUEUE_CONNECTION=sync', '',
        ]));
        touch($directory.'/.env', time() - 60);
        file_put_contents($directory.'/runner.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->useEnvironmentPath($argv[2]);
$app->useStoragePath($argv[2].'/storage');
$app->useBootstrapPath($argv[2].'/bootstrap');
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app->setBasePath($argv[2]);
exit($kernel->call('serve', ['--host' => '127.0.0.1', '--port' => $argv[3], '--tries' => 1]));
PHP);
        file_put_contents($directory.'/server.php', '<?php require '.var_export(base_path('vendor/autoload.php'), true).';'.PHP_EOL.<<<'PHP'
$writer = new App\Services\Installation\EnvironmentFileWriter(__DIR__);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $writer->update(['APP_NAME' => 'After', 'APP_INSTALLED' => false]);
    // Longer than artisan serve's five-second stop timeout, like migrations
    // on a slower machine. A premature reload must fail this request.
    sleep(7);
    $writer->update(['APP_INSTALLED' => true]);
}
header('Content-Type: application/json');
echo json_encode(['name' => $writer->value('APP_NAME'), 'installed' => $writer->value('APP_INSTALLED')]);
PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $process = new Process([PHP_BINARY, $directory.'/runner.php', base_path(), $directory, (string) $port], base_path());
        $client = new Client(['base_uri' => 'http://127.0.0.1:'.$port, 'timeout' => 20, 'proxy' => '']);

        try {
            $process->start();
            $ready = false;
            $deadline = microtime(true) + 10;
            do {
                $probe = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.1);
                if (is_resource($probe)) {
                    fclose($probe);
                    $ready = true;
                    break;
                }
                usleep(100_000);
            } while ($process->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($ready, 'Isolated development server did not start.');
            $this->assertSame('Before', json_decode((string) $client->get('/')->getBody(), true)['name']);
            $modifiedAt = filemtime($directory.'/.env');

            $response = $client->post('/install');
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('After', json_decode((string) $response->getBody(), true)['name']);
            $this->assertSame('true', json_decode((string) $response->getBody(), true)['installed']);
            $this->assertSame('After', json_decode((string) $client->get('/')->getBody(), true)['name']);
            clearstatcache(false, $directory.'/.env');
            $this->assertSame($modifiedAt, filemtime($directory.'/.env'));
            $this->assertStringNotContainsString('Environment modified. Restarting server', $process->getOutput());
        } finally {
            $process->stop(1);
            $resolved = realpath($directory);
            if ($resolved !== false && dirname($resolved) === realpath(sys_get_temp_dir())
                && str_starts_with(basename($resolved), 'siem-install-server-')) {
                (new Filesystem)->deleteDirectory($resolved);
            }
        }
    }
}
