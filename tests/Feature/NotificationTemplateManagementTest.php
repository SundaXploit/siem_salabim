<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_templates_without_rewriting_notification_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertFalse(Schema::hasColumn('notification_templates', 'icon'));
        $this->actingAs($admin)->get(route('settings.index', ['section' => 'templates']))->assertOk()->assertSee('Tambah template');
        $attributes = ['name' => 'Template SOC', 'icon' => '📢', 'category' => 'SOC', 'body' => '<b>Investigasi {agent_name}</b>', 'is_active' => true];
        $saved = $this->postJson(route('settings.templates.save'), $attributes)->assertOk();
        $this->assertArrayNotHasKey('icon', $saved->json('template'));
        $id = $saved->json('template.id');
        $alert = $this->assignAckOwner(Alert::create(['wazuh_alert_id' => 'template-management', 'agent_name' => 'Endpoint asli',
            'rule_id' => '550', 'rule_level' => 8, 'status' => 'new', 'raw_data' => ['agent' => ['id' => '001']]]), $admin);
        $message = NotificationTemplate::findOrFail($id)->render($alert);
        $log = NotificationLog::create(['alert_id' => $alert->id, 'user_id' => $admin->id, 'chat_id' => '-1001',
            'message' => $message, 'sent_at' => now(), 'response_status' => 200, 'delivery_state' => 'sent']);
        $ciphertext = DB::table('notification_logs')->where('id', $log->id)->value('message');

        $this->postJson(route('settings.templates.save'), [...$attributes, 'id' => $id, 'body' => 'Isi baru rahasia', 'is_active' => false])
            ->assertOk()->assertJsonPath('template.is_active', false);
        $this->getJson(route('alerts.template', $alert))->assertOk()->assertJsonMissing(['id' => $id]);
        $this->getJson(route('templates.list'))->assertOk()->assertJsonFragment(['id' => $id, 'is_active' => false]);
        $this->deleteJson(route('settings.templates.delete', $id))->assertOk();
        $this->assertDatabaseMissing('notification_templates', ['id' => $id]);
        $this->assertSame($ciphertext, DB::table('notification_logs')->where('id', $log->id)->value('message'));
        $this->assertSame($message, $log->fresh()->message);
        $this->get(route('analyst-log.index'))->assertOk()->assertSee($message);
        $this->assertSame(2, ActivityLog::where('action', 'notification_template_saved')->count());
        $this->assertSame(1, ActivityLog::where('action', 'notification_template_deleted')->count());
        $this->assertStringNotContainsString('Isi baru rahasia', ActivityLog::all()->toJson());
    }

    public function test_analysts_cannot_manage_or_read_template_bodies_from_settings_and_validation_is_bounded(): void
    {
        $template = NotificationTemplate::firstOrFail();
        $analyst = User::factory()->forAgent('001', array_keys(User::PRIVILEGES))->create();
        $this->actingAs($analyst)->getJson(route('templates.list'))->assertForbidden();
        $this->getJson(route('settings.index', ['section' => 'templates']))->assertForbidden();
        foreach (['templates.save', 'settings.templates.save'] as $route) {
            $this->postJson(route($route), ['name' => 'Forged', 'body' => 'Forged body'])->assertForbidden();
        }
        foreach (['templates.delete', 'settings.templates.delete'] as $route) {
            $this->deleteJson(route($route, $template))->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('settings.templates.save'), ['name' => 'Invalid', 'body' => str_repeat('x', 4097), 'is_active' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['body', 'is_active']);
        $this->postJson(route('settings.templates.save'), ['id' => 999999, 'name' => 'Invalid', 'body' => 'Valid body'])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_existing_custom_template_has_manual_empty_fields_and_later_admin_edits_are_preserved(): void
    {
        $custom = NotificationTemplate::where('name', 'Custom / Pesan Bebas')->sole();
        $this->assertSame(NotificationTemplate::CUSTOM_BODY, $custom->body);
        $this->assertStringNotContainsString('{agent_name}', $custom->body);
        $this->assertStringContainsString('<b>Prioritas:</b>', $custom->body);
        $this->assertStringNotContainsString('Judul tambahan', $custom->body);
        $count = NotificationTemplate::count();
        $custom->update(['body' => 'Template manual milik administrator']);
        $migration = require database_path('migrations/2026_11_15_000023_prepare_custom_notification_template.php');
        $migration->up();
        $this->assertSame($count, NotificationTemplate::count());
        $this->assertSame('Template manual milik administrator', $custom->fresh()->body);
    }

    public function test_legacy_custom_template_with_old_punctuation_still_migrates(): void
    {
        $legacy = <<<'TPL'
🚨 <b>SOC Subang - Notifikasi Alert</b>

📌 <b>Rule ID</b>   : {rule_id} (Level {rule_level})
📋 <b>Deskripsi</b> : {rule_description}
🖥️ <b>Agent</b>     : {agent_name}
🌐 <b>Source IP</b> : {src_ip}
🎯 <b>Dest IP</b>   : {dst_ip}
🕐 <b>Waktu</b>     : {timestamp}

[Tambahkan narasi analisis di sini]

<b>⚠️ Rekomendasi:</b>
[Tambahkan rekomendasi di sini]

<b>💥 Impact:</b>
[Tambahkan dampak potensial di sini]
TPL;
        $custom = NotificationTemplate::where('name', 'Custom / Pesan Bebas')->sole();
        $custom->update(['body' => str_replace('Subang -', 'Subang '.mb_chr(8212, 'UTF-8'), $legacy)]);
        $migration = require database_path('migrations/2026_11_15_000023_prepare_custom_notification_template.php');
        $migration->up();
        $this->assertSame(NotificationTemplate::CUSTOM_BODY, $custom->fresh()->body);
    }
}
