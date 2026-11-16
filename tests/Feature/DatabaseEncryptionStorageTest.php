<?php

namespace Tests\Feature;

use App\Services\SensitiveDataCipher;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseEncryptionStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = sys_get_temp_dir().'/siem-cache-encryption-test-'.bin2hex(random_bytes(8)).'.lock';
        config(['data_security.migration_lock_path' => $this->lockPath]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }
        parent::tearDown();
    }

    public function test_legacy_bulk_analysis_cache_is_repaired_encrypted_and_rotated_on_the_actual_database(): void
    {
        config(['cache.default' => 'database', 'data_security.allow_legacy_plaintext' => false]);
        $cache = Cache::store('database');
        $key = 'bulk-alert-ai:v1:database-encryption-audit';
        $evidence = ['summary' => 'private-cache-evidence', 'alert_ids' => [1, 2]];
        $cache->put($key, $evidence, 600);
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertFailed();
        $this->artisan('siem:encrypt-data', ['--allow-legacy' => true])->assertSuccessful();
        $this->assertSame($evidence, SensitiveDataCipher::decryptJson($cache->get($key)));

        $oldKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('siem:encrypt-data', ['--rotate' => true])->assertSuccessful();
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
        $this->assertSame($evidence, SensitiveDataCipher::decryptJson($cache->get($key)));
        $this->assertStringNotContainsString('private-cache-evidence', DB::table('cache')->value('value'));
        $this->assertFalse(config('data_security.allow_legacy_plaintext'));
    }

    public function test_encrypted_database_sessions_survive_key_rotation_and_are_rewritten_with_the_new_key(): void
    {
        $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, $this->app);
        $sessionId = Str::random(40);
        $store = new EncryptedStore('audit', $handler, app('encrypter'), $sessionId);
        $store->start();
        $store->put('evidence', 'private-session-evidence');
        $store->save();
        $this->assertStringNotContainsString('private-session-evidence', base64_decode(DB::table('sessions')->where('id', $sessionId)->value('payload')));

        $oldKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $rotated = new EncryptedStore('audit', $handler, app('encrypter'), $sessionId);
        $rotated->start();
        $this->assertSame('private-session-evidence', $rotated->get('evidence'));
        $rotated->save();
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $current = new EncryptedStore('audit', $handler, app('encrypter'), $sessionId);
        $current->start();
        $this->assertSame('private-session-evidence', $current->get('evidence'));
    }

    public function test_removing_an_expired_cache_batch_does_not_skip_the_following_live_evidence(): void
    {
        config(['cache.default' => 'database', 'data_security.allow_legacy_plaintext' => false]);
        $cache = Cache::store('database');
        $evidence = ['summary' => 'private live evidence'];
        $expired = [];
        for ($i = 0; $i < 100; $i++) {
            $expired['bulk-alert-ai:v1:expired-'.sprintf('%03d', $i)] = $evidence;
        }
        $cache->putMany($expired, 600);
        DB::table('cache')->update(['expiration' => time() - 1]);
        $live = 'bulk-alert-ai:v1:live-evidence';
        $cache->put($live, $evidence, 600);

        $this->artisan('siem:encrypt-data', ['--allow-legacy' => true])->assertSuccessful();
        $this->assertSame($evidence, SensitiveDataCipher::decryptJson($cache->get($live)));
        $this->assertSame(1, DB::table('cache')->count());
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
    }

    public function test_normal_migration_upgrades_authenticated_cbc_cache_without_requiring_rotate(): void
    {
        config(['cache.default' => 'database', 'data_security.allow_legacy_plaintext' => false]);
        $cache = Cache::store('database');
        $key = 'bulk-alert-ai:v1:old-cbc-evidence';
        $evidence = ['summary' => 'private CBC evidence'];
        $oldKey = random_bytes(32);
        $cbc = new Encrypter($oldKey, 'AES-256-CBC');
        $old = json_encode(SensitiveDataCipher::PREFIX.$cbc->encryptString(json_encode($evidence, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
        $cache->put($key, $old, 600);
        config(['app.previous_keys' => ['base64:'.base64_encode($oldKey)]]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        $this->artisan('siem:encrypt-data', ['--check' => true])->assertFailed();
        $this->artisan('siem:encrypt-data')->assertSuccessful();
        $this->assertNotSame($old, $cache->get($key));
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->assertSame($evidence, SensitiveDataCipher::decryptJson($cache->get($key)));
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
    }

    public function test_database_locks_and_queued_delivery_references_work_without_plaintext_evidence(): void
    {
        $cache = Cache::store('database');
        $first = $cache->lock('encryption-audit-lock', 60);
        $second = $cache->lock('encryption-audit-lock', 60);
        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertTrue($first->release());
        $this->assertTrue($second->get());
        $second->release();
        $queue = Queue::connection('database');
        $payload = json_encode(['displayName' => 'encryption-audit', 'job' => 'test-only', 'data' => ['notification_id' => 123]], JSON_THROW_ON_ERROR);
        $id = $queue->pushRaw($payload, 'encryption-audit');
        $job = $queue->pop('encryption-audit');
        $this->assertSame((int) $id, (int) $job->getJobId());
        $this->assertSame($payload, $job->getRawBody());
        $job->delete();
        $this->assertSame(0, $queue->size('encryption-audit'));
    }
}
