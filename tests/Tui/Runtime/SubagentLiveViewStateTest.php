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
        ];

        $view->enter($child);

        self::assertSame([$block], $view->childTranscript);
        self::assertSame(3, $view->childLastSeq);
        self::assertSame(RunActivityStateEnum::Completed, $view->childActivity);
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

        self::assertArrayHasKey('run-b', $view->childCaches);
        self::assertSame('cached', $view->childCaches['run-b']['transcript'][0]->text);
    }

    private function child(string $runId, string $artifactId): SubagentLiveChildDTO
    {
        return new SubagentLiveChildDTO(
            agentRunId: $runId,
            artifactId: $artifactId,
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Completed,
            taskSummary: 'task',
            lastActivityAtMs: 1,
        );
    }
}
