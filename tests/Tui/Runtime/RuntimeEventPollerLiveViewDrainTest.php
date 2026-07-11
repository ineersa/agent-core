<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(RuntimeEventPoller::class)]
final class RuntimeEventPollerLiveViewDrainTest extends TestCase
{
    #[Test]
    public function pollStateOnlyAdvancesSeqAndCatalogWithoutGrowingParentTranscript(): void
    {
        $runId = 'parent-live-drain';
        $client = $this->createStub(AgentSessionClient::class);
        $client->method('events')->willReturn([
            new RuntimeEvent(
                'tool_execution_update',
                $runId,
                2,
                [
                    'subagent_progress' => [
                        'mode' => 'single',
                        'status' => 'running',
                        'agent_name' => 'scout',
                        'artifact_id' => 'art-bg',
                        'agent_run_id' => 'child-bg',
                        'task_summary' => 'task',
                    ],
                ],
            ),
        ]);

        $projector = new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState());
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier($projector),
            new TestLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $state = new TuiSessionState($runId);
        $state->handle = new RunHandle($runId, 'running');
        $state->lastSeq = 1;
        $state->activity = RunActivityStateEnum::Running;
        $state->transcript = [
            new TranscriptBlock('p1', TranscriptBlockKindEnum::UserMessage, $runId, 1, 'parent'),
        ];

        $poller->pollStateOnly($state, $client);

        $this->assertSame(2, $state->lastSeq);
        $this->assertCount(1, $state->transcript, 'Parent transcript must not gain projected blocks during live-view drain');
        $this->assertNotNull($state->subagentLiveCatalog->findByArtifactId('art-bg'));
    }
}
