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
    public function testEnterRestoresCachedTranscriptWhenReselectingChild(): void
    {
        $view = new SubagentLiveViewState();
        $child = $this->child('run-a', 'agent_a');
        $block = new TranscriptBlock('b1', TranscriptBlockKindEnum::AssistantMessage, 'run-a', 1, 'done');
        $view->childCaches['run-a'] = [
            'transcript' => [$block],
            'lastSeq' => 3,
            'lastPoll' => 1.0,
            'activity' => RunActivityStateEnum::Completed,
            'taskSummary' => 'task',
        ];

        $view->enter($child);

        $this->assertSame([$block], $view->childTranscript);
        $this->assertSame(3, $view->childLastSeq);
        $this->assertSame(RunActivityStateEnum::Completed, $view->childActivity);
    }

    public function testPersistCurrentChildCacheStoresActiveChildSnapshot(): void
    {
        $view = new SubagentLiveViewState();
        $child = $this->child('run-b', 'agent_b');
        $view->enter($child);
        $view->childTranscript = [
            new TranscriptBlock('b2', TranscriptBlockKindEnum::AssistantMessage, 'run-b', 2, 'cached'),
        ];
        $view->childLastSeq = 5;
        $view->childActivity = RunActivityStateEnum::Running;

        $view->persistCurrentChildCache();

        $this->assertArrayHasKey('run-b', $view->childCaches);
        $this->assertSame('cached', $view->childCaches['run-b']['transcript'][0]->text);
        $this->assertSame('task', $view->childCaches['run-b']['taskSummary']);
    }

    public function testChildQueuedMessagesPersistInCache(): void
    {
        $view = new SubagentLiveViewState();
        $child = $this->child('run-q', 'agent_q');
        $view->enter($child);
        $view->childQueuedUserMessages = ['k1' => 'steer next'];
        $view->persistCurrentChildCache();

        $this->assertSame(['k1' => 'steer next'], $view->childCaches['run-q']['queuedUserMessages']);
    }

    public function testEnterReusesTranscriptButResetsLifecycleAcrossResumeTaskGeneration(): void
    {
        $view = new SubagentLiveViewState();
        $completed = $this->child('run-resume', 'agent_resume', SubagentLiveStatusEnum::Completed, 'Task A');
        $block = new TranscriptBlock('b-a', TranscriptBlockKindEnum::AssistantMessage, 'run-resume', 4, 'task a done');
        $view->enter($completed);
        $view->childTranscript = [$block];
        $view->childLastSeq = 4;
        $view->childActivity = RunActivityStateEnum::Completed;
        $view->childQueuedUserMessages = ['old' => 'steer from task a'];
        $view->persistCurrentChildCache();
        $view->exit();

        $resumed = $this->child('run-resume', 'agent_resume', SubagentLiveStatusEnum::Running, 'Task B');
        $view->enter($resumed);

        $this->assertSame([$block], $view->childTranscript);
        $this->assertSame(4, $view->childLastSeq);
        $this->assertSame(RunActivityStateEnum::Running, $view->childActivity);
        $this->assertSame([], $view->childQueuedUserMessages);
        $this->assertSame([], $view->childReplayEvents);
        $this->assertSame('Task B', $view->childCaches['run-resume']['taskSummary']);
        $this->assertSame(RunActivityStateEnum::Running, $view->childCaches['run-resume']['activity']);
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
