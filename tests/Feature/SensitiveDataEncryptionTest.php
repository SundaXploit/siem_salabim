<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\DashboardInsight;
use App\Models\DataExport;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\EncryptedSearchIndex;
use App\Services\OpenSearchService;
use App\Services\SecurityInsightSnapshotService;
use App\Services\SensitiveDataCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SensitiveDataEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockPath = sys_get_temp_dir().'/siem-encryption-test-'.bin2hex(random_bytes(8)).'.lock';
        config(['data_security.allow_legacy_plaintext' => false,
            'data_security.migration_lock_path' => $this->lockPath,
            'data_security.blind_index_key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            unlink($this->lockPath);
        }
        parent::tearDown();
    }

    private function alert(string $suffix = 'one'): Alert
    {
        return Alert::create(['wazuh_alert_id' => 'encrypted-'.$suffix, 'rule_id' => '53001', 'rule_level' => 14,
            'status' => 'new', 'source_cluster' => 'cluster-a', 'source_index' => 'wazuh-fixture', 'source_id' => $suffix,
            'first_seen_at' => now(), 'src_ip' => '192.0.2.48', 'dst_ip' => '2001:db8::7', 'agent_name' => 'Endpoint',
            'raw_data' => ['agent' => ['id' => '001'], 'data' => ['user' => 'private-operator', 'commandLine' => 'powershell.exe -EncodedCommand protected-evidence-marker']]]);
    }

    public function test_payload_ip_ai_evidence_and_notification_copies_are_ciphertext_while_metadata_stays_queryable(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->alert();
        $analysis = $alert->aiAnalyses()->create(['verdict' => 'suspicious', 'confidence' => 88, 'summary' => 'private-summary',
            'supporting_indicators' => ['private-evidence'], 'legitimate_indicators' => ['private-normal'],
            'recommended_checks' => ['private-check'], 'limitations' => ['private-limitation'], 'model' => 'fixture-model', 'generated_at' => now()]);
        $log = NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $user->id, 'chat_id' => '-1001',
            'message' => 'private-notification', 'sent_at' => now(), 'delivery_details' => ['message' => ['state' => 'pending'], 'private' => 'private-details']]);
        $stored = DB::table('alerts')->where('id', $alert->id)->first();
        $this->assertStringNotContainsString('private-operator', $stored->raw_data);
        $this->assertStringNotContainsString('192.0.2.48', $stored->src_ip);
        $this->assertSame('53001', $stored->rule_id);
        $this->assertSame(14, (int) $stored->rule_level);
        $this->assertSame('cluster-a', $stored->source_cluster);
        $this->assertSame(Alert::sourceIdentityHash('cluster-a', 'wazuh-fixture', 'one'), $stored->source_identity_hash);
        $this->assertSame('private-operator', $alert->fresh()->raw_data['data']['user']);
        $this->assertSame('192.0.2.48', $alert->fresh()->src_ip);
        $this->assertSame('private-summary', $analysis->fresh()->toResult()['summary']);
        $this->assertSame(['private-evidence'], $analysis->fresh()->supporting_indicators);
        $this->assertStringNotContainsString('private-', json_encode((array) DB::table('alert_ai_analyses')->where('id', $analysis->id)->first()));
        $this->assertStringNotContainsString('private-notification', DB::table('notification_logs')->where('id', $log->id)->value('message'));
        $this->assertStringNotContainsString('private-details', DB::table('notification_logs')->where('id', $log->id)->value('delivery_details'));
        $this->assertSame('private-details', $log->fresh()->delivery_details['private']);
        $envelope = json_decode(base64_decode(substr(json_decode($stored->raw_data, true), strlen(SensitiveDataCipher::PREFIX))), true);
        $this->assertSame(16, strlen(base64_decode($envelope['tag'])));
        $this->assertSame(12, strlen(base64_decode($envelope['iv'])));
    }

    public function test_plaintext_is_readable_before_migration_but_ciphertext_tampering_never_falls_back(): void
    {
        config(['data_security.allow_legacy_plaintext' => true]);
        $alert = $this->alert();
        DB::table('alerts')->where('id', $alert->id)->update(['raw_data' => json_encode(['legacy' => 'old-event']), 'src_ip' => '198.51.100.9']);
        $this->assertSame(['legacy' => 'old-event'], $alert->fresh()->raw_data);
        $this->assertSame('198.51.100.9', $alert->fresh()->src_ip);
        $bad = json_encode(SensitiveDataCipher::PREFIX.'invalid-ciphertext');
        DB::table('alerts')->where('id', $alert->id)->update(['raw_data' => $bad]);
        // Hydration and indexed aggregates do not decrypt payloads.
        $hydrated = Alert::findOrFail($alert->id);
        $this->assertSame(1, Alert::where('rule_level', '>=', 12)->count());
        $this->expectException(DecryptException::class);
        $hydrated->raw_data;
    }

    public function test_strict_mode_rejects_plaintext_and_an_invalid_gcm_tag(): void
    {
        $alert = $this->alert();
        $value = json_decode($alert->getRawOriginal('raw_data'), true);
        $envelope = json_decode(base64_decode(substr($value, strlen(SensitiveDataCipher::PREFIX))), true);
        $envelope['tag'] = base64_encode(str_repeat("\0", 16));
        DB::table('alerts')->where('id', $alert->id)->update(['raw_data' => json_encode(SensitiveDataCipher::PREFIX.base64_encode(json_encode($envelope)))]);
        config(['data_security.allow_legacy_plaintext' => false]);
        try {
            $alert->fresh()->raw_data;
            $this->fail('Tampered GCM tag was accepted.');
        } catch (DecryptException) {
            $this->assertTrue(true);
        }
        DB::table('alerts')->where('id', $alert->id)->update(['raw_data' => '{"legacy":"downgrade"}']);
        $this->expectException(DecryptException::class);
        $alert->fresh()->raw_data;
    }

    #[DataProvider('missingEnvelopeFields')]
    public function test_legacy_mode_cannot_treat_a_damaged_unprefixed_envelope_as_plaintext(string $field): void
    {
        Configuration::set('ai_api_key', 'private credential');
        $stored = DB::table('configurations')->where('key', 'ai_api_key')->value('value');
        $envelope = json_decode(base64_decode($stored), true, 512, JSON_THROW_ON_ERROR);
        unset($envelope[$field]);
        DB::table('configurations')->where('key', 'ai_api_key')->update(['value' => base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR))]);
        config(['data_security.allow_legacy_plaintext' => true]);
        $this->expectException(DecryptException::class);
        Configuration::get('ai_api_key');
    }

    public static function missingEnvelopeFields(): array
    {
        return [['iv'], ['value'], ['mac']];
    }

    public function test_previous_keys_and_authenticated_cbc_remain_readable_after_switching_to_gcm(): void
    {
        $old = random_bytes(32);
        $new = random_bytes(32);
        $cbc = new Encrypter($old, 'AES-256-CBC');
        $legacy = $cbc->encryptString('legacy-credential');
        config(['app.key' => 'base64:'.base64_encode($new), 'app.previous_keys' => ['base64:'.base64_encode($old)], 'app.cipher' => 'AES-256-GCM']);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->assertSame('legacy-credential', Crypt::decryptString($legacy));
        DB::table('configurations')->insert(['key' => 'opensearch_password', 'value' => $legacy]);
        $this->assertSame('legacy-credential', Configuration::get('opensearch_password'));
        Configuration::set('opensearch_password', 'new-credential');
        $stored = DB::table('configurations')->where('key', 'opensearch_password')->value('value');
        $this->assertSame(16, strlen(base64_decode(json_decode(base64_decode($stored), true)['tag'])));
        $this->assertSame('new-credential', Crypt::decryptString($stored));
        $corrupt = json_decode(base64_decode($legacy), true);
        $corrupt['mac'] = str_repeat('0', 64);
        DB::table('configurations')->where('key', 'opensearch_password')->update(['value' => base64_encode(json_encode($corrupt))]);
        $this->expectException(DecryptException::class);
        Configuration::get('opensearch_password');
    }

    public function test_rotation_reencrypts_every_sensitive_model_and_keeps_search_and_original_evidence_usable_without_old_keys(): void
    {
        $oldKey = config('app.key');
        config(['app.cipher' => 'AES-256-CBC']);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->alert();
        $raw = $alert->raw_data;
        $hash = $alert->getRawOriginal('src_ip_hash');
        $ciphertext = $alert->getRawOriginal('raw_data');
        $analysis = $alert->aiAnalyses()->create([
            'verdict' => 'suspicious', 'summary' => 'private rotation analysis', 'model' => 'fixture',
            'supporting_indicators' => ['private indicator'], 'generated_at' => now(),
        ]);
        $triage = AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $user->id, 'action' => 'ignore', 'reason' => 'private rotation note']);
        $delivery = NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => $user->id, 'chat_id' => '-1001', 'message' => 'private rotation notification',
            'delivery_details' => ['message' => ['state' => 'sent']], 'sent_at' => now(),
        ]);
        $insight = DashboardInsight::create([
            'period_key' => '2026-11', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'summary' => 'private rotation dashboard', 'source_snapshot' => ['private' => 'rotation evidence'],
        ]);
        $export = DataExport::forceCreate([
            'uuid' => (string) Str::uuid(), 'requested_by_name' => 'Admin', 'scope' => 'all', 'format' => 'json',
            'period_label' => 'all', 'storage_disk' => 'local', 'filename' => 'rotation.zip', 'archive_password' => 'private rotation password',
        ]);
        Configuration::set('ai_api_key', 'private rotation credential');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey], 'app.cipher' => 'AES-256-GCM']);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('siem:encrypt-data', ['--rotate' => true, '--chunk' => 1])->assertSuccessful();
        config(['app.previous_keys' => []]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();

        $this->assertSame($raw, $alert->fresh()->raw_data);
        $this->assertNotSame($ciphertext, $alert->fresh()->getRawOriginal('raw_data'));
        $this->assertSame($hash, $alert->fresh()->getRawOriginal('src_ip_hash'));
        $this->assertSame('private rotation analysis', $analysis->fresh()->summary);
        $this->assertSame(['private indicator'], $analysis->fresh()->supporting_indicators);
        $this->assertSame('private rotation note', $triage->fresh()->reason);
        $this->assertSame('private rotation notification', $delivery->fresh()->message);
        $this->assertSame(['message' => ['state' => 'sent']], $delivery->fresh()->delivery_details);
        $this->assertSame('private rotation dashboard', $insight->fresh()->summary);
        $this->assertSame(['private' => 'rotation evidence'], $insight->fresh()->source_snapshot);
        $this->assertSame('private rotation password', $export->fresh()->archive_password);
        $this->assertSame('private rotation credential', Configuration::get('ai_api_key'));
        foreach (['192.0.2.48', 'protected-evidence-marker'] as $term) {
            $this->assertSame([$alert->id], Alert::searchTerms($term)->pluck('id')->all());
        }
    }

    public function test_blind_indexes_find_exact_ips_and_log_words_without_plaintext_or_decrypting_every_row(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->alert();
        $other = $this->alert('two');
        $this->assertNotSame($alert->getRawOriginal('src_ip'), $other->getRawOriginal('src_ip'));
        $this->assertSame($alert->getRawOriginal('src_ip_hash'), $other->getRawOriginal('src_ip_hash'));
        $this->assertSame(1, DB::table('alerts')->distinct()->count('src_ip_hash'));
        $this->assertStringNotContainsString('protected-evidence-marker', json_encode(DB::table('encrypted_search_tokens')->get()));
        config(['data_security.allow_legacy_plaintext' => false]);
        $this->actingAs($user);
        foreach (['192.0.2.48', '2001:0db8:0:0:0:0:0:7', 'EncodedCommand protected-evidence-marker', 'private-operator'] as $term) {
            $this->get(route('alerts.index', ['status' => 'all', 'search' => $term]))->assertOk()
                ->assertViewHas('alerts', fn ($rows) => $rows->total() === 2);
        }
        $this->get(route('alerts.index', ['search' => "' OR 1=1; DROP TABLE alerts; --"]))->assertOk()
            ->assertViewHas('alerts', fn ($rows) => $rows->total() === 0);
        $this->assertSame(2, Alert::count());
        $hash = $alert->getRawOriginal('src_ip_hash');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertSame($hash, EncryptedSearchIndex::hash('ip', '192.0.2.48'));
    }

    public function test_backfill_is_resumable_preserves_metadata_and_is_idempotent_without_rotation(): void
    {
        config(['data_security.allow_legacy_plaintext' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        $alert = $this->alert();
        $raw = ['agent' => ['id' => '001'], 'legacy' => 'sensitive-legacy-marker'];
        DB::table('alerts')->where('id', $alert->id)->update(['raw_data' => json_encode($raw), 'src_ip' => '198.51.100.8', 'src_ip_hash' => null]);
        $triage = AlertTriage::create(['alert_id' => $alert->id, 'user_id' => $user->id, 'action' => 'ignore', 'reason' => 'private-note']);
        DB::table('alert_triages')->where('id', $triage->id)->update(['reason' => 'legacy-note']);
        DB::table('configurations')->insert(['key' => 'ai_api_key', 'value' => 'legacy-api-key']);
        $metadata = DB::table('alerts')->where('id', $alert->id)->first(['status', 'first_seen_at', 'source_identity_hash', 'updated_at']);
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertFailed();
        $this->artisan('siem:encrypt-data', ['--chunk' => 1])->assertSuccessful();
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
        $before = DB::table('alerts')->where('id', $alert->id)->first();
        $tokens = DB::table('encrypted_search_tokens')->orderBy('id')->get()->toArray();
        $this->artisan('siem:encrypt-data')->assertSuccessful();
        $this->assertEquals($before, DB::table('alerts')->where('id', $alert->id)->first());
        $this->assertEquals($tokens, DB::table('encrypted_search_tokens')->orderBy('id')->get()->toArray());
        $this->assertEquals($metadata, DB::table('alerts')->where('id', $alert->id)->first(['status', 'first_seen_at', 'source_identity_hash', 'updated_at']));
        config(['data_security.allow_legacy_plaintext' => false]);
        $this->assertSame($raw, $alert->fresh()->raw_data);
        $this->assertSame('legacy-note', $triage->fresh()->reason);
        $this->assertSame('legacy-api-key', Configuration::get('ai_api_key'));
        $this->assertSame(1, Alert::searchTerms('sensitive-legacy-marker')->count());
    }

    public function test_dashboard_uses_mitre_metadata_without_decrypting_log_payloads(): void
    {
        $alert = $this->alert();
        $raw = $alert->raw_data;
        $raw['rule']['mitre'] = ['id' => ['T1078'], 'technique' => ['Valid Accounts']];
        $alert->update(['raw_data' => $raw]);
        DB::table('alerts')->where('id', $alert->id)->update(['raw_data' => json_encode(SensitiveDataCipher::PREFIX.'damaged-payload')]);
        config(['data_security.allow_legacy_plaintext' => false]);
        $service = app(SecurityInsightSnapshotService::class);
        $snapshot = $service->build($service->currentPeriod(), true);
        $this->assertSame(1, $snapshot['metrics']['total']);
        $this->assertStringContainsString('Valid Accounts', json_encode($snapshot['techniques']));
        $this->assertSame(1, $snapshot['metrics']['unique_source_ips']);
    }

    #[DataProvider('notificationHistoryViewerRoles')]
    public function test_explicit_legacy_repair_restores_existing_automatic_notification_details_without_weakening_web_reads(string $role): void
    {
        $alert = $this->alert();
        $original = $alert->getRawOriginal('raw_data');
        $message = 'Pesan otomatis lama yang perlu dienkripsi ulang.';
        $delivery = NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => null, 'chat_id' => '-1001', 'message' => 'Pesan sebelum job dikirim.',
            'automatic_key' => hash('sha256', 'legacy-automatic-delivery'), 'delivery_state' => 'sent',
            'response_status' => 200, 'sent_at' => now(),
        ]);
        DB::table('notification_logs')->where('id', $delivery->id)->update(['message' => $message]);
        config(['data_security.allow_legacy_plaintext' => false]);
        $viewer = $role === 'admin' ? User::factory()->create(['role' => 'admin'])
            : User::factory()->forAgent('001', cluster: 'cluster-a')->create();
        $this->actingAs($viewer)
            ->get(route('alerts.show', $alert))->assertStatus(500);
        $this->get(route('analyst-log.index'))->assertStatus(500);
        $this->get(route('analyst-log.index', ['action' => 'notify']))->assertStatus(500);
        $this->get(route('analyst-log.index', ['action' => 'acknowledge']))->assertOk();
        $this->get(route('analyst-log.index', ['action' => 'ignore']))->assertOk();
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertFailed();
        $this->artisan('siem:encrypt-data', ['--allow-legacy' => true])->assertSuccessful();
        $this->assertFalse(config('data_security.allow_legacy_plaintext'));
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
        $this->get(route('alerts.show', $alert))->assertOk()->assertSee('Sistem (otomatis)')->assertSee($message);
        foreach ([[], ['action' => 'notify'], ['search' => 'dienkripsi ulang']] as $filters) {
            $this->get(route('analyst-log.index', $filters))->assertOk()->assertSee('Sistem (otomatis)')->assertSee($message)
                ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->alert_id === $alert->id);
        }
        $this->assertTrue(app(EncryptedSearchIndex::class)->matches('notification', $delivery->id, $alert->id, [$message]));
        $this->assertStringStartsWith(SensitiveDataCipher::PREFIX, $delivery->fresh()->getRawOriginal('message'));
        $this->assertSame($message, $delivery->fresh()->message);
        $this->assertSame('sent', $delivery->fresh()->delivery_state);
        $this->assertNull($delivery->fresh()->user_id);
        $this->assertSame($original, $alert->fresh()->getRawOriginal('raw_data'));
        $this->assertDatabaseCount('alert_triages', 0);
        $repaired = $delivery->fresh()->getAttributes();
        $this->artisan('siem:encrypt-data', ['--allow-legacy' => true])->assertSuccessful();
        $this->assertSame($repaired, $delivery->fresh()->getAttributes());
        $this->assertFalse(config('data_security.allow_legacy_plaintext'));
    }

    public static function notificationHistoryViewerRoles(): array
    {
        return ['admin' => ['admin'], 'assigned analyst' => ['analyst']];
    }

    public function test_explicit_legacy_repair_still_rejects_damaged_ciphertext_and_restores_strict_mode_on_failure(): void
    {
        $alert = $this->alert();
        $delivery = NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => null, 'chat_id' => '-1001', 'message' => 'Notification evidence.',
            'automatic_key' => hash('sha256', 'damaged-automatic-delivery'), 'delivery_state' => 'sent', 'sent_at' => now(),
        ]);
        $damaged = SensitiveDataCipher::PREFIX.'damaged-ciphertext';
        DB::table('notification_logs')->where('id', $delivery->id)->update(['message' => $damaged]);
        config(['data_security.allow_legacy_plaintext' => false]);
        $this->artisan('siem:encrypt-data', ['--allow-legacy' => true])->assertFailed();
        $this->assertFalse(config('data_security.allow_legacy_plaintext'));
        $this->assertSame($damaged, $delivery->fresh()->getRawOriginal('message'));
        $this->expectException(DecryptException::class);
        $delivery->fresh()->message;
    }

    public function test_search_refuses_index_corruption_and_respects_agent_visibility_after_rotation(): void
    {
        $alert = $this->alert();
        $analyst = User::factory()->forAgent('001')->create();
        $analyst->update(['allowed_agents' => [['key' => $alert->agent_scope_key, 'id' => '001', 'name' => 'Endpoint', 'source_cluster' => 'cluster-a']]]);
        $foreign = $this->alert('foreign');
        $foreign->forceFill(['source_cluster' => 'cluster-b', 'agent_scope_key' => Alert::agentScopeKey('cluster-b', '001')])->save();
        config(['data_security.allow_legacy_plaintext' => false]);
        $this->actingAs($analyst)->get(route('alerts.index', ['search' => 'protected-evidence-marker']))->assertOk()
            ->assertViewHas('alerts', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $alert->id);
        DB::table('encrypted_search_tokens')->where('entity', 'alert')->where('record_id', $alert->id)->delete();
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertFailed();
        $this->artisan('siem:encrypt-data')->assertSuccessful();
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
    }

    public function test_ingestion_bulk_upsert_encrypts_payload_and_ips_and_indexes_the_original_words(): void
    {
        $source = ['@timestamp' => now('UTC')->toIso8601String(), 'agent' => ['id' => '001', 'name' => 'batch-endpoint'],
            'rule' => ['id' => '53001', 'level' => 14, 'description' => 'Batch fixture', 'mitre' => ['id' => ['T1078'], 'technique' => ['Valid Accounts']]],
            'data' => ['srcip' => '192.0.2.99', 'dstip' => '198.51.100.99', 'message' => 'private-batch-evidence']];
        $hit = ['_id' => 'encrypted-batch-id', '_index' => 'wazuh-fixture-2026.11.15', '_source' => $source];
        $this->mock(OpenSearchService::class)->shouldReceive('fetchAlertBatches')->once()->andReturn([[$hit]]);
        $this->artisan('siem:fetch-alerts', ['--all' => true])->assertSuccessful();
        $alert = Alert::sole();
        $this->assertSame($source, $alert->raw_data);
        $this->assertSame('192.0.2.99', $alert->src_ip);
        $this->assertStringNotContainsString('192.0.2.99', $alert->getRawOriginal('src_ip'));
        $this->assertStringNotContainsString('private-batch-evidence', $alert->getRawOriginal('raw_data'));
        $this->assertSame(['id' => ['T1078'], 'technique' => ['Valid Accounts']], $alert->mitre_metadata);
        config(['data_security.allow_legacy_plaintext' => false]);
        $this->assertSame(1, Alert::searchTerms('private-batch-evidence')->count());
        $this->assertSame(1, Alert::searchTerms('192.0.2.99')->count());
    }

    public function test_an_active_migration_lock_is_rejected_and_can_be_retried_after_release(): void
    {
        $lock = fopen($this->lockPath, 'c+');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('siem:encrypt-data', ['--check' => true])->assertFailed();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->artisan('siem:encrypt-data', ['--check' => true])->assertSuccessful();
    }

    public function test_word_index_can_be_disabled_while_payloads_and_exact_ip_search_stay_protected(): void
    {
        config(['data_security.search_max_tokens' => 0, 'data_security.allow_legacy_plaintext' => false]);
        $alert = $this->alert();
        $this->assertSame(0, DB::table('encrypted_search_tokens')->count());
        $this->assertSame(1, Alert::searchTerms('192.0.2.48')->count());
        $this->assertStringNotContainsString('private-operator', $alert->getRawOriginal('raw_data'));
    }
}
