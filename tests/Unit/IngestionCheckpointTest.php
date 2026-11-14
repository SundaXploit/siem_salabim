<?php

namespace Tests\Unit;

use App\Models\IngestionCheckpoint;
use PHPUnit\Framework\TestCase;

class IngestionCheckpointTest extends TestCase
{
    public function test_reads_utc_watermarks_with_and_without_mysql_fractional_seconds(): void
    {
        foreach (['', '.000000', '.123456'] as $fraction) {
            $stored = '2026-11-12 01:53:22'.$fraction;
            $checkpoint = (new IngestionCheckpoint)->setRawAttributes([
                'cursor_at' => $stored,
                'last_completed_at' => $stored,
            ]);

            foreach (['cursor_at', 'last_completed_at'] as $field) {
                $this->assertSame(
                    '2026-11-12 01:53:22'.($fraction ?: '.000000').'+00:00',
                    $checkpoint->$field->format('Y-m-d H:i:s.uP'),
                );
            }
        }
    }
}
