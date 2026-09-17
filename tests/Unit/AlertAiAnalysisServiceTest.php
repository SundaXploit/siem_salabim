<?php

namespace Tests\Unit;

use App\Services\AlertAiAnalysisService;
use Tests\TestCase;

class AlertAiAnalysisServiceTest extends TestCase
{
    public function test_it_repairs_illegal_json_escapes_from_provider(): void
    {
        $text = <<<'JSON'
{"verdict":"likely\_legitimate","confidence":70,"summary":"Cleanmgr membuat C:\\\Windows\\\Temp\\\DismHost.exe","supporting\_indicators":[],"legitimate\_indicators":["Pola bawaan Windows"],"recommended\_checks":["Validasi hash"],"limitations":[]}
JSON;

        $result = (new AlertAiAnalysisService())->normaliseProviderText($text, 'test-model');

        $this->assertSame('likely_legitimate', $result['verdict']);
        $this->assertSame(70, $result['confidence']);
        $this->assertStringContainsString('DismHost.exe', $result['summary']);
        $this->assertSame(['Pola bawaan Windows'], $result['legitimate_indicators']);
    }

    public function test_it_salvages_complete_fields_from_a_truncated_json_array(): void
    {
        $text = <<<'JSON'
{"verdict":"likely\_legitimate","confidence":70,"summary":"Aktivitas konsisten dengan Disk Cleanup Windows.","supporting\_indicators":["Executable dibuat di Temp"],"legitimate\_indicators":["Parent process cleanmgr.exe"],"recommended\_checks":["Validasi hash","Konfirmasi
JSON;

        $result = (new AlertAiAnalysisService())->normaliseProviderText($text, 'test-model');

        $this->assertSame('likely_legitimate', $result['verdict']);
        $this->assertSame(70, $result['confidence']);
        $this->assertSame('Aktivitas konsisten dengan Disk Cleanup Windows.', $result['summary']);
        $this->assertSame(['Validasi hash'], $result['recommended_checks']);
        $this->assertSame('test-model', $result['model']);
    }

    public function test_partial_object_does_not_leak_raw_json_into_summary(): void
    {
        $result = (new AlertAiAnalysisService())->normaliseProviderText(
            '{"verdict":"likely_legitimate","summary":',
            'test-model',
        );

        $this->assertSame('likely_legitimate', $result['verdict']);
        $this->assertSame('Bukti belum cukup untuk menghasilkan ringkasan.', $result['summary']);
        $this->assertStringNotContainsString('{"verdict"', $result['summary']);
    }
}
