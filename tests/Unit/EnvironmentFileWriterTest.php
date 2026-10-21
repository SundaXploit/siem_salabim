<?php

namespace Tests\Unit;

use App\Services\Installation\EnvironmentFileWriter;
use Dotenv\Dotenv;
use RuntimeException;
use Tests\TestCase;

class EnvironmentFileWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'siem-env-writer-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_creates_environment_and_generates_a_stable_key_once(): void
    {
        file_put_contents($this->directory.'/.env.example', "APP_NAME=SIEM\nAPP_KEY=\nAPP_DEBUG=false\n");
        $writer = new EnvironmentFileWriter($this->directory);

        $first = $writer->ensureEnvironmentAndKey();
        $second = $writer->ensureEnvironmentAndKey();

        $this->assertTrue($first['environment_created']);
        $this->assertTrue($first['key_created']);
        $this->assertFalse($second['environment_created']);
        $this->assertFalse($second['key_created']);
        $this->assertSame($first['app_key'], $second['app_key']);
        $this->assertStringStartsWith('base64:', $first['app_key']);
        $this->assertSame(32, strlen(base64_decode(substr($first['app_key'], 7), true)));
        $this->assertSame($first['app_key'], $writer->value('APP_KEY'));
        $this->assertFileExists($this->directory.'/storage/framework/siem-environment.lock');
        $this->assertFileDoesNotExist($this->directory.'/.env.install.lock');
    }

    public function test_it_round_trips_special_characters_without_duplicate_assignments(): void
    {
        file_put_contents(
            $this->directory.'/.env.example',
            "APP_KEY=\nSMTP_PASSWORD=old\nSMTP_PASSWORD=duplicate\n",
        );
        $writer = new EnvironmentFileWriter($this->directory);
        $password = "p@ss # quote=\" dollar=\${VALUE} slash=\\ tab=\t line=\nnext";

        $writer->update([
            'APP_URL' => 'https://siem.example.test:8443/path?q=a=b',
            'SMTP_PASSWORD' => $password,
            'APP_DEBUG' => false,
            'OPTIONAL_VALUE' => null,
        ]);

        $loaded = Dotenv::createArrayBacked($this->directory)->load();
        $contents = file_get_contents($this->directory.'/.env');

        $this->assertSame('https://siem.example.test:8443/path?q=a=b', $loaded['APP_URL']);
        $this->assertSame($password, $loaded['SMTP_PASSWORD']);
        $this->assertSame($password, $writer->value('SMTP_PASSWORD'));
        $this->assertSame('false', $loaded['APP_DEBUG']);
        $this->assertSame('', $loaded['OPTIONAL_VALUE']);
        $this->assertSame(1, preg_match_all('/^SMTP_PASSWORD=/m', $contents));
    }

    public function test_it_refuses_to_rotate_an_existing_application_key(): void
    {
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\n");
        $writer = new EnvironmentFileWriter($this->directory);
        $result = $writer->ensureEnvironmentAndKey();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');

        $writer->update(['APP_KEY' => $result['app_key'].'changed']);
    }

    public function test_it_persists_the_runtime_application_key_for_a_fresh_environment(): void
    {
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\n");
        $writer = new EnvironmentFileWriter($this->directory);
        $runtimeKey = 'base64:'.base64_encode(random_bytes(32));

        $result = $writer->prepare(['APP_KEY' => $runtimeKey]);

        $this->assertTrue($result['key_created']);
        $this->assertSame($runtimeKey, $result['app_key']);
        $this->assertSame($runtimeKey, $writer->value('APP_KEY'));
    }

    public function test_it_reads_a_quoted_value_with_a_trailing_comment(): void
    {
        file_put_contents($this->directory.'/.env', "APP_KEY=\"base64:stable-key\" # keep this key\n");
        file_put_contents($this->directory.'/.env.example', "APP_KEY=\n");
        $writer = new EnvironmentFileWriter($this->directory);

        $result = $writer->ensureEnvironmentAndKey();

        $this->assertFalse($result['key_created']);
        $this->assertSame('base64:stable-key', $result['app_key']);
    }

    public function test_it_requires_an_example_for_a_fresh_environment(): void
    {
        $writer = new EnvironmentFileWriter($this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.env.example');

        $writer->ensureEnvironmentAndKey();
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
