<?php

namespace Tests\Unit;

use App\Models\IngestionCheckpoint;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngestionCheckpointTest extends TestCase
{
    public static function databaseTimestamps(): array
    {
        return [
            ['2026-10-21 01:02:03', '2026-10-21 01:02:03.000000'],
            ['2026-10-21 01:02:03.000000', '2026-10-21 01:02:03.000000'],
            ['2026-10-21 01:02:03.123456', '2026-10-21 01:02:03.123456'],
            ['2026-10-21 01:02:03.12', '2026-10-21 01:02:03.120000'],
        ];
    }

    #[DataProvider('databaseTimestamps')]
    public function test_reads_database_watermarks_as_utc_with_optional_microseconds(string $stored, string $expected): void
    {
        $checkpoint = (new IngestionCheckpoint)->newFromBuilder(['cursor_at' => $stored, 'last_completed_at' => $stored]);
        $this->assertSame($expected, $checkpoint->cursor_at->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $checkpoint->cursor_at->timezoneName);
        $this->assertSame($expected, $checkpoint->last_completed_at->format('Y-m-d H:i:s.u'));
    }

    public function test_writes_utc_without_losing_microseconds(): void
    {
        $checkpoint = new IngestionCheckpoint([
            'cursor_at' => CarbonImmutable::parse('2026-10-21 08:02:03.123456', 'Asia/Jakarta'),
        ]);
        $this->assertSame('2026-10-21 01:02:03.123456', $checkpoint->getAttributes()['cursor_at']);
    }
}
