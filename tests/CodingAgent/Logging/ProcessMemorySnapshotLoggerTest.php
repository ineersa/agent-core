<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Logging\ProcessMemorySnapshotLogger;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessMemorySnapshotLogger::class)]
final class ProcessMemorySnapshotLoggerTest extends TestCase
{
    public function testCheckpointEmitsScalarLifecycleFieldsOnly(): void
    {
        $logger = new TestLogger();
        $snapshot = new ProcessMemorySnapshotLogger($logger);

        $snapshot->checkpoint('tui.resume.mounted', 'tui', [
            'session_id' => '2',
            'run_id' => '2',
            'transcript_block_count' => 3,
            'nested' => ['forbidden' => true],
        ]);

        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('info', $record['level']);
        $this->assertSame('process.memory.checkpoint', $record['message']);
        $this->assertSame('tui', $record['context']['component']);
        $this->assertSame('tui.resume.mounted', $record['context']['event_type']);
        $this->assertSame('2', $record['context']['session_id']);
        $this->assertSame(3, $record['context']['transcript_block_count']);
        $this->assertArrayNotHasKey('nested', $record['context']);
    }

    public function testTranscriptScalarsCountResidentBlocksWithoutContent(): void
    {
        $blocks = [
            new TranscriptBlock('u1', TranscriptBlockKindEnum::UserMessage, '2', 1, 'hello'),
            new TranscriptBlock('a1', TranscriptBlockKindEnum::AssistantMessage, '2', 2, 'world!!'),
            new TranscriptBlock('t1', TranscriptBlockKindEnum::ToolCall, '2', 3, 'call'),
            new TranscriptBlock('r1', TranscriptBlockKindEnum::ToolResult, '2', 4, 'result-bytes'),
        ];

        $scalars = ProcessMemorySnapshotLogger::transcriptScalars($blocks);

        $this->assertSame(4, $scalars['transcript_block_count']);
        $this->assertSame(\strlen('hello') + \strlen('world!!') + \strlen('call') + \strlen('result-bytes'), $scalars['transcript_text_bytes']);
        $this->assertSame(1, $scalars['kind_user_message']);
        $this->assertSame(1, $scalars['kind_assistant_message']);
        $this->assertSame(1, $scalars['kind_tool_call']);
        $this->assertSame(1, $scalars['kind_tool_result']);
        $this->assertSame(0, $scalars['kind_system']);
    }

    public function testParentTranscriptScopeLabelsParentEvenForChildVisibleRun(): void
    {
        $scope = ProcessMemorySnapshotLogger::parentTranscriptScope('child-run-9', true);

        $this->assertSame('parent', $scope['transcript_scope']);
        $this->assertSame('child-run-9', $scope['visible_run_id']);
        $this->assertTrue($scope['live_child_view']);
    }
}
