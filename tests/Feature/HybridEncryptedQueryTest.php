<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\EncryptedSearchIndex;
use App\Services\SensitiveDataCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HybridEncryptedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['data_security.allow_legacy_plaintext' => false]);
    }

    private function alert(string $id, string $message, string $agent = '007', array $attributes = []): Alert
    {
        return Alert::create([
            'wazuh_alert_id' => $id, 'rule_description' => 'Suricata audit event', 'rule_id' => '53001',
            'agent_name' => 'Audit endpoint', 'rule_level' => 14, 'status' => 'new', 'first_seen_at' => now(),
            'raw_data' => ['agent' => ['id' => $agent], 'full_log' => $message], ...$attributes,
        ]);
    }

    #[DataProvider('embeddedIps')]
    public function test_strict_search_normalizes_ip_addresses_embedded_in_log_and_notification_text(string $stored, string $search): void
    {
        $alert = $this->alert('embedded-ip', 'client='.$stored.'; action=connect');
        $user = User::factory()->forAgent('007')->create();
        NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => $user->id, 'chat_id' => '-1001',
            'message' => 'client='.$stored.'; investigate this connection', 'sent_at' => now(),
        ]);

        $this->assertSame([$alert->id], Alert::visibleTo($user)->searchTerms($search)->pluck('id')->all());
        $this->actingAs($user)->get(route('analyst-log.index', ['search' => $search]))->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1);
    }

    public static function embeddedIps(): array
    {
        return [
            'expanded IPv6' => ['2001:0DB8:0000:0000:0000:0000:0000:0042', '2001:db8::42'],
            'compressed IPv6' => ['[2001:db8::42]:443', '2001:0db8:0:0:0:0:0:42'],
            'IPv4-mapped IPv6' => ['[::ffff:192.0.2.42]:443', '::ffff:192.0.2.42'],
            'IPv4 socket' => ['192.0.2.42:443', '192.0.2.42'],
        ];
    }

    #[DataProvider('metadataFields')]
    public function test_metadata_and_encrypted_terms_have_consistent_case_insensitive_search(string $field): void
    {
        $alert = $this->alert('metadata-case', 'unrelated evidence', attributes: [$field => 'MeTa-CaSe-OnLy']);
        $this->assertSame([$alert->id], Alert::searchTerms('meta-case-only')->pluck('id')->all());
    }

    public static function metadataFields(): array
    {
        return array_map(fn ($field) => [$field], ['rule_description', 'agent_name', 'rule_id', 'wazuh_alert_id']);
    }

    #[DataProvider('unicodeWords')]
    public function test_encrypted_word_search_preserves_unicode_combining_marks_and_canonical_equivalence(string $stored, string $search): void
    {
        $alert = $this->alert('unicode-word', $stored);
        $this->assertSame([$alert->id], Alert::searchTerms($search)->pluck('id')->all());
        $this->assertSame($stored, $alert->fresh()->raw_data['full_log']);
    }

    public static function unicodeWords(): array
    {
        return [
            ['İstanbul', 'İSTANBUL'],
            ["e\u{0301}ve\u{0301}nement", 'ÉVÉNEMENT'],
            ['한글', '한글'],
        ];
    }

    public function test_hybrid_queries_combine_terms_without_decrypting_payloads_or_disclosing_other_agents(): void
    {
        $allowed = $this->alert('allowed-hybrid', 'mixed-secret ÉVÉNEMENT 東京');
        $this->alert('foreign-hybrid', 'mixed-secret ÉVÉNEMENT 東京', '008');
        $viewer = User::factory()->forAgent('007')->create();
        DB::table('alerts')->where('id', $allowed->id)->update([
            'raw_data' => json_encode(SensitiveDataCipher::PREFIX.'damaged-payload'),
        ]);
        foreach (['mixed-secret suricata', 'événement 東京'] as $search) {
            $this->assertSame([$allowed->id], Alert::visibleTo($viewer)->searchTerms($search)->pluck('id')->all());
        }
        $this->assertSame([], Alert::visibleTo($viewer)->searchTerms("' OR 1=1; --")->pluck('id')->all());
    }

    public function test_history_metadata_and_system_actor_search_are_case_insensitive(): void
    {
        $alert = $this->alert('history-case', 'audit log', attributes: ['agent_name' => 'UNIQUE-ENDPOINT']);
        NotificationLog::create([
            'alert_id' => $alert->id, 'user_id' => null, 'chat_id' => '-1001', 'message' => 'notification evidence',
            'automatic_key' => hash('sha256', 'history-system-case'), 'delivery_state' => 'sent', 'sent_at' => now(),
        ]);
        $this->actingAs(User::factory()->forAgent('007')->create());
        foreach (['unique-endpoint', 'OTOMATIS'] as $search) {
            $this->get(route('analyst-log.index', ['search' => $search]))->assertOk()
                ->assertViewHas('logs', fn ($logs) => $logs->total() === 1);
        }
    }

    public function test_index_integrity_checks_detect_and_repair_an_incorrect_alert_parent(): void
    {
        $alert = $this->alert('index-owner', 'original index evidence');
        $other = $this->alert('index-other', 'unrelated evidence');
        DB::table('encrypted_search_tokens')->where('entity', 'alert')->where('record_id', $alert->id)
            ->update(['alert_id' => $other->id]);
        $values = [$alert->raw_data, $alert->src_ip, $alert->dst_ip];
        $index = app(EncryptedSearchIndex::class);
        $this->assertFalse($index->matches('alert', $alert->id, $alert->id, $values));
        $index->replace('alert', $alert->id, $alert->id, $values);
        $this->assertTrue($index->matches('alert', $alert->id, $alert->id, $values));
        $other->delete();
        $this->assertSame([$alert->id], Alert::searchTerms('original index evidence')->pluck('id')->all());
    }

    public function test_moving_a_notification_updates_its_index_before_the_old_alert_is_deleted(): void
    {
        $old = $this->alert('old-parent', 'old evidence');
        $current = $this->alert('current-parent', 'current evidence');
        $log = NotificationLog::create([
            'alert_id' => $old->id, 'user_id' => null, 'chat_id' => '-1001', 'message' => 'reparent-note', 'sent_at' => now(),
        ]);
        $log->update(['alert_id' => $current->id]);
        $old->delete();
        $this->actingAs(User::factory()->forAgent('007')->create())
            ->get(route('analyst-log.index', ['search' => 'reparent-note']))->assertOk()
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1 && $logs->first()->alert_id === $current->id);
    }
}
