<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeIntegrationDns();
        Http::preventStrayRequests();
        config(['services.ai.reasoning_effort' => 'none', 'services.ai.base_url' => 'https://ai.example.test/v1']);
    }

    public function test_successful_http_without_answer_is_not_reported_as_working_ai(): void
    {
        Http::fake(['*/responses' => Http::response(['status' => 'completed', 'output' => []])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.ai'), $this->connectionSettings())
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'Endpoint AI terhubung, tetapi tidak mengembalikan teks jawaban. Periksa model dan batas token.');
        Http::assertSentCount(1);
    }

    public function test_connection_check_requests_a_non_thinking_answer(): void
    {
        Http::fake(['*/responses' => Http::response(['output_text' => 'pong'])]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('settings.test.ai'), $this->connectionSettings())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('reply', 'Respons AI diterima.');
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'reasoning.effort') === 'none');
    }

    private function connectionSettings(): array
    {
        return [
            'ai_provider' => 'custom',
            'ai_api_format' => 'responses',
            'ai_api_key' => 'test-only-key',
            'ai_base_url' => 'https://ai.example.test/v1',
            'ai_model' => 'test/deepseek-v4.1-flash',
        ];
    }
}
