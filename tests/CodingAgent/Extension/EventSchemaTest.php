<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\HatfieldExt\Hatfield360\EventSchema;
use PHPUnit\Framework\TestCase;

final class EventSchemaTest extends TestCase
{
    public function testScheduleAndTriggerBounds(): void
    {
        $snapshot = ['provider' => 'hatfield', 'turn' => ['id' => 't', 'status' => 'completed', 'trigger' => 'schedule'], 'attention' => null, 'activeSubagents' => 0, 'backgroundScripts' => 0, 'tool' => null];
        $entry = ['id' => 'abc', 'label' => str_repeat('🙂', 64), 'recurring' => true, 'nextRunAt' => null];
        $entries = array_map(static fn (int $i): array => ['id' => (string) $i] + $entry, range(1, 16));
        foreach ([null, [], $entries, [['nextRunAt' => 0] + $entry]] as $schedules) {
            $this->assertTrue(EventSchema::accepts('session.snapshot', 's', 1, 1, $snapshot + ['schedules' => $schedules]));
        }
        $missingTime = $entry;
        unset($missingTime['nextRunAt']);
        foreach ([
            [$entry, ...$entries], [$entry, $entry], [$missingTime], ['abc' => $entry],
            [['id' => 'a/b'] + $entry], [['label' => str_repeat('🙂', 65)] + $entry],
            [['label' => "\xED\xA0\x80"] + $entry], [['recurring' => 1] + $entry],
            [['nextRunAt' => -1] + $entry], [['nextRunAt' => 0.5] + $entry],
            [['nextRunAt' => 9_007_199_254_740_992] + $entry], [$entry + ['arguments' => []]],
        ] as $schedules) {
            $this->assertFalse(EventSchema::accepts('session.snapshot', 's', 1, 1, $snapshot + ['schedules' => $schedules]));
        }
        foreach ([null, 'child'] as $trigger) {
            $this->assertFalse(EventSchema::accepts('session.snapshot', 's', 1, 1, ['turn' => ['trigger' => $trigger] + $snapshot['turn']] + $snapshot));
        }
        $this->assertFalse(EventSchema::accepts('session.snapshot', 's', 1, 1, ['turn' => $snapshot['turn'] + ['identity' => 'abc']] + $snapshot));
    }
}
