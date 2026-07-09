<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveBackgroundChildPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** @covers \Ineersa\Tui\Runtime\SubagentLiveBackgroundChildPoller */
final class SubagentLiveBackgroundChildPollerTest extends TestCase
{
    public function testDiscoversNestedScoutFromForkRunStreamWithoutLiveView(): void
    {
        $parentRun = 'parent-1';
        $forkRun = 'fork-run-9';
        $scoutRun = 'scout-run-9';
        $forkArtifact = 'agent_fork';
        $scoutArtifact = 'agent_scout';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'fork',
                    'artifact_id' => $forkArtifact,
                    'agent_run_id' => $forkRun,
                    'task_summary' => 'delegate',
                ],
            ],
        ));

        $client = new BufferedChildEventsClient([
            $forkRun => [
                new RuntimeEvent(
                    'tool_execution_update',
                    $forkRun,
                    2,
                    [
                        'subagent_progress' => [
                            'mode' => 'single',
                            'status' => 'running',
                            'agent_name' => 'scout',
                            'artifact_id' => $scoutArtifact,
                            'agent_run_id' => $scoutRun,
                            'task_summary' => 'list docs',
                        ],
                    ],
                ),
            ],
        ]);

        $screen = $this->chatScreen($parentRun);
        $poller = new SubagentLiveBackgroundChildPoller(new NullLogger());
        $poller->poll($state, $client, $screen);

        $scout = $state->subagentLiveCatalog->findByArtifactId($scoutArtifact);
        $this->assertNotNull($scout);
        $this->assertSame($scoutRun, $scout->agentRunId);
        $this->assertSame(SubagentLiveStatusEnum::Running, $scout->status);
    }

    public function testNestedScoutHumanInputEnqueuesQuestionForScoutRunId(): void
    {
        $parentRun = 'parent-2';
        $forkRun = 'fork-run-2';
        $scoutRun = 'scout-run-2';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'fork',
                    'artifact_id' => 'agent_fork',
                    'agent_run_id' => $forkRun,
                    'task_summary' => 'delegate',
                ],
            ],
        ));
        $state->subagentLiveCatalog->ingestNestedProgressFromChildRunEvent(new RuntimeEvent(
            'tool_execution_update',
            $forkRun,
            2,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_scout',
                    'agent_run_id' => $scoutRun,
                    'task_summary' => 'pick file',
                ],
            ],
        ));
        $state->subagentLiveCatalog->applyChildStatus('agent_scout', SubagentLiveStatusEnum::WaitingHuman);

        $sent = null;
        $client = new BufferedChildEventsClient([
            $forkRun => [],
            $scoutRun => [
                new RuntimeEvent(
                    RuntimeEventTypeEnum::HumanInputRequested->value,
                    $scoutRun,
                    3,
                    [
                        'question_id' => 'q_nested',
                        'ui_kind' => 'choice',
                        'prompt' => 'Which file?',
                        'schema' => ['type' => 'string'],
                        'choices' => [['value' => 'a.md', 'label' => 'a.md']],
                    ],
                ),
            ],
        ]);
        $client->onSend = static function (string $runId, UserCommand $cmd) use (&$sent): void {
            $sent = [$runId, $cmd];
        };

        $coordinator = new QuestionCoordinator();
        $screen = $this->chatScreen($parentRun);
        $poller = new SubagentLiveBackgroundChildPoller(new NullLogger());
        $poller->poll(
            $state,
            $client,
            $screen,
            onHumanInputRequested: static function (RuntimeEvent $event) use ($coordinator, $client, $state, $screen): void {
                (new \Ineersa\Tui\Listener\RuntimeQuestionEventHandler())->handleHumanInputRequested(
                    $event,
                    $client,
                    $coordinator,
                    $state,
                    $screen,
                );
            },
        );

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame($scoutRun, $active->runId);
        $this->assertFalse($active->transcript);
        $this->assertSame('Child agent scout asks', $active->header);
        $this->assertSame(SubagentLiveStatusEnum::WaitingHuman, $state->subagentLiveCatalog->findByArtifactId('agent_scout')?->status);

        $coordinator->answer('a.md');
        $this->assertNotNull($sent);
        $this->assertSame($scoutRun, $sent[0]);
        $this->assertSame('answer_human', $sent[1]->type);
    }

    public function testPollCatalogIngestDoesNotConsumeStoredBackfillBeforeLiveViewPoll(): void
    {
        $parentRun = 'parent-backfill';
        $scoutRun = 'scout-run-backfill';
        $scoutArtifact = 'agent_scout_bf';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveBackgroundLastPoll = 0.0;
        $state->subagentLiveCatalog->ingestNestedProgressFromChildRunEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'waiting_human',
                    'agent_name' => 'scout',
                    'artifact_id' => $scoutArtifact,
                    'agent_run_id' => $scoutRun,
                    'task_summary' => 'pick file',
                ],
            ],
        ));
        $state->subagentLiveCatalog->applyChildStatus($scoutArtifact, SubagentLiveStatusEnum::WaitingHuman);

        $storedHitl = new RuntimeEvent(
            RuntimeEventTypeEnum::HumanInputRequested->value,
            $scoutRun,
            2,
            [
                'question_id' => 'q_scout_bf',
                'prompt' => 'Which file should the scout inspect next?',
                'schema' => ['type' => 'string'],
            ],
        );

        $backfill = new OneShotTrackingBackfillProvider([$scoutRun => [$storedHitl]]);
        $client = new BufferedChildEventsClient([$scoutRun => []]);

        $bgPoller = new SubagentLiveBackgroundChildPoller(new NullLogger(), $backfill);
        $bgPoller->pollCatalogIngest($state, $client);

        $this->assertSame(0, $backfill->callCountFor($scoutRun), 'catalog ingest must not consume durable backfill');

        $projector = $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface::class);
        $projector->method('blocks')->willReturn([
            new \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock(
                id: 'block-scout',
                kind: \Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum::Progress,
                runId: $scoutRun,
                seq: 2,
                text: 'Which file should the scout inspect next?',
            ),
        ]);

        $childPoller = new \Ineersa\Tui\Runtime\SubagentLiveChildViewPoller(
            projector: $projector,
            logger: new NullLogger(),
            backfillProvider: $backfill,
        );

        $live = new \Ineersa\Tui\Runtime\SubagentLiveViewState();
        $live->enter(new SubagentLiveChildDTO(
            $scoutRun,
            $scoutArtifact,
            'scout',
            SubagentLiveStatusEnum::WaitingHuman,
            'pick file',
            1,
        ));
        $live->childLastPoll = 0.0;

        $hitlSeen = false;
        $blocks = $childPoller->poll(
            live: $live,
            client: $client,
            onHumanInputRequested: static function (RuntimeEvent $event) use (&$hitlSeen): void {
                $hitlSeen = true;
            },
        );

        $this->assertSame(1, $backfill->callCountFor($scoutRun), 'selected live poller owns one-shot backfill');
        $this->assertNotNull($blocks, 'live view must project stored events instead of staying on loading placeholder');
        $this->assertTrue($hitlSeen, 'stored waiting_human must fire HITL on selected child live poller');
    }

    public function testPollCatalogIngestDiscoversNestedScoutWhileLiveViewOnFork(): void
    {
        $parentRun = 'parent-1';
        $forkRun = 'fork-run';
        $scoutRun = 'scout-run';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'fork',
                    'artifact_id' => 'agent_fork',
                    'agent_run_id' => $forkRun,
                    'task_summary' => 'delegate',
                ],
            ],
        ));
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            $forkRun,
            'agent_fork',
            'fork',
            SubagentLiveStatusEnum::Running,
            'delegate',
            1,
        ));

        $client = new BufferedChildEventsClient([
            $forkRun => [
                new RuntimeEvent(
                    'tool_execution_update',
                    $forkRun,
                    2,
                    [
                        'subagent_progress' => [
                            'mode' => 'single',
                            'status' => 'running',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_scout',
                            'agent_run_id' => $scoutRun,
                            'task_summary' => 'pick file',
                        ],
                    ],
                ),
            ],
            $scoutRun => [],
        ]);

        $poller = new SubagentLiveBackgroundChildPoller(new NullLogger());
        $poller->pollCatalogIngest($state, $client);

        $scout = $state->subagentLiveCatalog->findByArtifactId('agent_scout');
        $this->assertNotNull($scout);
        $this->assertSame($scoutRun, $scout->agentRunId);
        $this->assertSame('scout', $scout->agentName);
    }

    private function chatScreen(string $sessionId): ChatScreen
    {
        return new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $sessionId,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
    }
}

final class OneShotTrackingBackfillProvider implements \Ineersa\CodingAgent\Runtime\Contract\BackfillEventProviderInterface
{
    /** @var array<string, int> */
    private array $calls = [];

    /**
     * @param array<string, list<RuntimeEvent>> $eventsByRun
     */
    public function __construct(private readonly array $eventsByRun)
    {
    }

    public function getStoredEvents(string $runId): array
    {
        $this->calls[$runId] = ($this->calls[$runId] ?? 0) + 1;

        return $this->eventsByRun[$runId] ?? [];
    }

    public function callCountFor(string $runId): int
    {
        return $this->calls[$runId] ?? 0;
    }
}

/**
 * @implements AgentSessionClient
 */
final class BufferedChildEventsClient implements AgentSessionClient
{
    /** @var callable(string, UserCommand): void|null */
    public $onSend;

    /**
     * @param array<string, list<RuntimeEvent>> $eventsByRun
     */
    public function __construct(private array $eventsByRun)
    {
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \BadMethodCallException();
    }

    public function send(string $runId, UserCommand $command): void
    {
        if (null !== $this->onSend) {
            ($this->onSend)($runId, $command);
        }
    }

    public function attach(string $runId): RunHandle
    {
        throw new \BadMethodCallException();
    }

    public function cancel(string $runId): void
    {
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \BadMethodCallException();
    }

    public function completeRun(string $runId): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }

    public function events(string $runId): iterable
    {
        return $this->eventsByRun[$runId] ?? [];
    }
}
