<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use PHPUnit\Framework\TestCase;

/**
 * Forward status→activity mapping shared by TickPollListener and
 * SubagentLiveViewState.
 *
 * @covers \Ineersa\Tui\Runtime\SubagentLiveStatusEnum
 */
final class SubagentLiveStatusEnumTest extends TestCase
{
    public function testToActivityMapsEveryStatus(): void
    {
        $statuses = [
            SubagentLiveStatusEnum::Pending,
            SubagentLiveStatusEnum::Running,
            SubagentLiveStatusEnum::WaitingHuman,
            SubagentLiveStatusEnum::Completed,
            SubagentLiveStatusEnum::Done,
            SubagentLiveStatusEnum::Failed,
            SubagentLiveStatusEnum::Cancelled,
            SubagentLiveStatusEnum::Unknown,
        ];
        $expected = [
            RunActivityStateEnum::Running,
            RunActivityStateEnum::Running,
            RunActivityStateEnum::WaitingHuman,
            RunActivityStateEnum::Completed,
            RunActivityStateEnum::Completed,
            RunActivityStateEnum::Failed,
            RunActivityStateEnum::Cancelled,
            null,
        ];

        foreach ($statuses as $i => $status) {
            $this->assertSame($expected[$i], $status->toActivity());
        }
    }
}
