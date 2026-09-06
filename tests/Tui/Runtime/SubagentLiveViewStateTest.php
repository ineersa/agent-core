<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\SubagentLiveViewState;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\Tui\Runtime\SubagentLiveViewState */
final class SubagentLiveViewStateTest extends TestCase
{
    public function testEnterResetsProjectedStateFromCatalogChild(): void
    {
        $view = new SubagentLiveViewState();
        $view->childTranscript = [
            new TranscriptBlock('old', TranscriptBlockKindEnum::AssistantMessage, 'run-old', 9, 'stale'),
        ];
        $view->childLastSeq = 9;
        $view->childQueuedUserMessages = ['k' => 'steer'];

        $view->enter($this->child('run-a', 'agent_a', SubagentLiveStatusEnum::Running));

        $this->assertTrue($view->active);
        $this->assertSame('run-a', $view->selected?->agentRunId);
        $this->assertSame([], $view->childTranscript);
        $this->assertSame(0, $view->childLastSeq);
        $this->assertSame([], $view->childQueuedUserMessages);
        $this->assertSame(RunActivityStateEnum::Running, $view->childActivity);
    }

    public function testExitClearsSelectedAndProjectedState(): void
    {
        $view = new SubagentLiveViewState();
        $view->enter($this->child('run-b', 'agent_b', SubagentLiveStatusEnum::WaitingHuman));
        $view->childTranscript = [
            new TranscriptBlock('b2', TranscriptBlockKindEnum::AssistantMessage, 'run-b', 2, 'live'),
        ];
        $view->childLastSeq = 2;
        $view->childQueuedUserMessages = ['k1' => 'steer next'];
        $view->lastLiveWorkingMessage = 'working';

        $view->exit();

        $this->assertFalse($view->active);
        $this->assertNull($view->selected);
        $this->assertSame([], $view->childTranscript);
        $this->assertSame(0, $view->childLastSeq);
        $this->assertSame([], $view->childQueuedUserMessages);
        $this->assertNull($view->lastLiveWorkingMessage);
        $this->assertSame(RunActivityStateEnum::Idle, $view->childActivity);
    }

    private function child(
        string $runId,
        string $artifactId,
        SubagentLiveStatusEnum $status = SubagentLiveStatusEnum::Completed,
        string $taskSummary = 'task',
    ): SubagentLiveChildDTO {
        return new SubagentLiveChildDTO(
            agentRunId: $runId,
            artifactId: $artifactId,
            agentName: 'scout',
            status: $status,
            taskSummary: $taskSummary,
            lastActivityAtMs: 1,
            model: 'deepseek/deepseek-v4-flash',
            reasoning: 'medium',
        );
    }
}
