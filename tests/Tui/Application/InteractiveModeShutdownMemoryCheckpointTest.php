<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Application\InteractiveMode;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InteractiveMode::class)]
final class InteractiveModeShutdownMemoryCheckpointTest extends TestCase
{
    public function testFinalizeSessionExitLogsShutdownBeforeSessionEnded(): void
    {
        $logger = new TestLogger();
        $snapshot = new ProcessMemorySnapshotLogger($logger);

        $mode = (new \ReflectionClass(InteractiveMode::class))->newInstanceWithoutConstructor();
        $memoryRef = new \ReflectionProperty(InteractiveMode::class, 'memorySnapshotLogger');
        $memoryRef->setValue($mode, $snapshot);

        $state = new TuiSessionState('session-shutdown');
        $state->activity = RunActivityStateEnum::Idle;
        $state->lastSeq = 42;
        $state->replaceTranscript([
            new TranscriptBlock(
                id: 'u1',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'session-shutdown',
                seq: 1,
                text: 'hello',
            ),
        ]);

        $lifecycle = new TuiSessionLifecycleDispatcher();
        $order = [];
        $lifecycle->subscribe(static function (TuiSessionLifecycleEventTypeEnum $eventType) use (&$order, $logger): void {
            if (TuiSessionLifecycleEventTypeEnum::SessionEnded !== $eventType) {
                return;
            }
            $order[] = 'session_ended';
            $order[] = 'shutdown_records_before_ended:'.\count($logger->records);
        });

        $method = new \ReflectionMethod(InteractiveMode::class, 'finalizeSessionExit');
        $method->invoke($mode, $lifecycle, $state, null, null);

        $this->assertSame(
            ['session_ended', 'shutdown_records_before_ended:1'],
            $order,
            'Shutdown checkpoint must be persisted before SessionEnded subscribers run',
        );
        $this->assertCount(1, $logger->records);
        $this->assertSame('process.memory.checkpoint', $logger->records[0]['message']);
        $this->assertSame('tui.session.shutdown', $logger->records[0]['context']['event_type']);
        $this->assertSame('quit', $logger->records[0]['context']['exit_reason']);
        $this->assertSame('parent', $logger->records[0]['context']['transcript_scope']);
        $this->assertSame('session-shutdown', $logger->records[0]['context']['visible_run_id']);
        $this->assertFalse($logger->records[0]['context']['live_child_view']);
        $this->assertSame(1, $logger->records[0]['context']['transcript_block_count']);
    }
}
