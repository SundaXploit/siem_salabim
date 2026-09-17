<?php

namespace Tests\Unit;

use App\Services\AmanaiInsightService;
use PHPUnit\Framework\TestCase;

class AmanaiInsightServiceTest extends TestCase
{
    public function test_it_normalises_markdown_and_accepts_a_complete_conclusion(): void
    {
        $body = '**Ringkasan keamanan**' . "\n\n" . str_repeat(
            'Pola autentikasi meningkat dan harus diverifikasi melalui triage serta korelasi bukti. ',
            4
        );

        $service = new AmanaiInsightService();
        $result = $service->normaliseSummary($body);

        $this->assertStringStartsWith('Ringkasan keamanan', $result);
        $this->assertStringNotContainsString('**', $result);
        $this->assertTrue($service->isMeaningfulSummary($result));
    }

    public function test_it_rejects_a_markdown_only_response(): void
    {
        $service = new AmanaiInsightService();

        $this->assertSame('', $service->normaliseSummary('**'));
        $this->assertFalse($service->isMeaningfulSummary('**'));
    }
}
