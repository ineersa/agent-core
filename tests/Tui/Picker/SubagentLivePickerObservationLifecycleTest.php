<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** @covers \Ineersa\Tui\Picker\SubagentLivePickerController */
final class SubagentLivePickerObservationLifecycleTest extends TestCase
{
    public function testEnterLiveViewBeginsObservationBeforeSnapshotReplay(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'obs-before-snapshot');
        $state = new TuiSessionState('obs-before-snapshot');
        $child = new SubagentLiveChildDTO('child-run-1', 'art-1', 'scout', SubagentLiveStatusEnum::Completed, 'task', 1);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            type: 'tool_execution.output_delta',
            runId: 'obs-before-snapshot',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc1',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'completed',
                    'agent_name' => 'scout',
                    'artifact_id' => 'art-1',
                    'agent_run_id' => 'child-run-1',
                    'task_summary' => 'task',
                ],
            ],
        ));

        $spy = new ObservingSpyClient();
        $snapshotProvider = new FixedChildRunTranscriptSnapshotProvider(
            new ChildRunTranscriptSnapshotDTO([], [], 0),
        );

        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
            ),
            $snapshotProvider,
            $this->createStub(ChildAgentEventsPathResolverInterface::class),
            new SessionEventsExportService(),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), $state, $spy);

        $method = new \ReflectionMethod(SubagentLivePickerController::class, 'enterLiveView');
        $method->invoke($picker, $child, $state, $harness->screen());

        $this->assertSame(['begin:child-run-1'], $spy->calls);
        $this->assertTrue($state->subagentLiveView->active);
    }
}

final class ObservingSpyClient implements AgentSessionClient
{
    /** @var list<string> */
    public array $calls = [];

    public function beginObservingChildRun(string $childRunId): void
    {
        $this->calls[] = 'begin:'.$childRunId;
    }

    public function endObservingChildRun(string $childRunId): void
    {
        $this->calls[] = 'end:'.$childRunId;
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('not used');
    }

    public function attach(string $runId): RunHandle
    {
        throw new \RuntimeException('not used');
    }

    public function send(string $runId, UserCommand $command): void
    {
    }

    public function events(string $runId): iterable
    {
        return [];
    }

    public function cancel(string $runId): void
    {
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \RuntimeException('not used');
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}

final class FixedChildRunTranscriptSnapshotProvider implements ChildRunTranscriptSnapshotProviderInterface
{
    public function __construct(private readonly ChildRunTranscriptSnapshotDTO $snapshot)
    {
    }

    public function snapshot(string $childRunId): ChildRunTranscriptSnapshotDTO
    {
        return $this->snapshot;
    }
}
