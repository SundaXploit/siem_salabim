<?php

namespace Tests\Feature;

use App\Models\Configuration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_starts_with_seven_collapsed_sections_and_inline_forms(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('settings.index'))->assertOk();

        $this->assertSame(7, substr_count($response->getContent(), 'data-settings-section='));
        $response->assertDontSee('Versi &amp; update aplikasi', false)
            ->assertDontSee('settings-directory-edit', false)
            ->assertDontSee('Semua pengaturan')
            ->assertDontSee('data-settings-section="alerts"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(7, $xpath->query('//details[@data-settings-section and not(@open)]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-settings-section="templates"]/summary')->length);
        $this->assertSame(1, $xpath->query('//details[@data-settings-section="opensearch"]//form[@action="'.route('settings.opensearch').'"]//select[@name="min_alert_level"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-settings-section="opensearch"]//form[@action="'.route('settings.fetch-now').'"]')->length);
        foreach (['opensearch', 'permanent-ignore', 'ai', 'telegram', 'users', 'data'] as $section) {
            $this->assertSame(1, $xpath->query('//details[@data-settings-section="'.$section.'"]/summary')->length);
            $this->assertGreaterThan(0, $xpath->query('//details[@data-settings-section="'.$section.'"]//input[@name="_section" and @value="'.$section.'"]')->length);
        }
    }

    public function test_section_links_expand_the_selected_section_with_the_directory_still_visible(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['opensearch', 'permanent-ignore', 'ai', 'telegram', 'templates', 'users', 'data'] as $section) {
            $response = $this->get(route('settings.index', ['section' => $section]))
                ->assertOk()->assertViewHas('activeSettingsSection', $section)
                ->assertSee('Daftar pengaturan');
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame(7, $xpath->query('//details[@data-settings-section]')->length);
            $this->assertSame(1, $xpath->query('//details[@data-settings-section and @open]')->length);
            $this->assertSame($section, $xpath->query('//details[@data-settings-section and @open]')->item(0)->getAttribute('data-settings-section'));
        }
        $this->get('/settings?section=alerts')->assertSee('id="alert-min-alert-level"', false)
            ->assertViewHas('activeSettingsSection', 'opensearch')
            ->assertSee('Fetch alert manual')->assertSee(route('settings.fetch-now'), false);
        $this->get('/settings?section=unknown')->assertNotFound();
    }

    public function test_combined_opensearch_and_alert_form_saves_together_and_keeps_its_panel_open(): void
    {
        $payload = [
            '_section' => 'opensearch',
            'opensearch_host' => 'https://indexer.example.test:9200',
            'opensearch_username' => 'service-user',
            'opensearch_password' => '',
            'opensearch_index' => 'wazuh-alerts-*',
            'min_alert_level' => 7,
        ];
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->from('/settings')
            ->post(route('settings.opensearch'), $payload)
            ->assertRedirect('/settings')->assertSessionHasNoErrors();
        $this->assertSame('7', Configuration::get('min_alert_level'));
        $this->assertSame($payload['opensearch_host'], Configuration::get('opensearch_host'));
        $response = $this->get('/settings')->assertOk()->assertViewHas('activeSettingsSection', 'opensearch');
        $this->assertMatchesRegularExpression('/<details[^>]+data-settings-section="opensearch"[^>]*\sopen(?:\s|>)/', $response->getContent());

        $this->from('/settings')
            ->post(route('settings.opensearch'), array_replace($payload, ['opensearch_host' => 'https://other-indexer.example.test:9200', 'min_alert_level' => 4]))
            ->assertRedirect('/settings')->assertSessionHasErrors('min_alert_level');
        $this->assertSame('7', Configuration::get('min_alert_level'));
        $this->assertSame($payload['opensearch_host'], Configuration::get('opensearch_host'));
        $response = $this->get('/settings')->assertOk()->assertViewHas('activeSettingsSection', 'opensearch');
        $this->assertMatchesRegularExpression('/<details[^>]+data-settings-section="opensearch"[^>]*\sopen(?:\s|>)/', $response->getContent());
    }

    public function test_alert_threshold_can_be_saved_independently_of_ai(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->from('/settings?section=alerts')
            ->post(route('settings.alerts'), ['min_alert_level' => 7])
            ->assertRedirect('/settings?section=alerts')->assertSessionHasNoErrors();
        $this->assertSame('7', Configuration::get('min_alert_level'));
        $this->assertNull(Configuration::get('ai_api_key'));

        $this->postJson(route('settings.alerts'), ['min_alert_level' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('min_alert_level');
        $this->assertSame('7', Configuration::get('min_alert_level'));
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->post(route('settings.alerts'), ['min_alert_level' => 15])->assertForbidden();
    }

    public function test_ai_save_does_not_change_the_alert_import_threshold(): void
    {
        Configuration::set('min_alert_level', 14);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('settings.ai'), [
                'ai_provider' => 'ollama', 'ai_api_format' => 'chat_completions',
                'ai_base_url' => 'http://127.0.0.1:11434/v1', 'ai_model' => 'local-model',
                'ai_api_key' => '', 'ai_snapshot_limit' => 10000, 'ai_refresh_days' => 10,
                'min_alert_level' => 3,
            ])->assertSessionHasNoErrors();
        $this->assertSame('14', Configuration::get('min_alert_level'));
    }
}
