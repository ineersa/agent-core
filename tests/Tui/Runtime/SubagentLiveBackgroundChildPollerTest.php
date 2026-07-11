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

    public function testNestedScoutHumanInputDeferredUntilLiveViewActive(): void
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

        $this->assertFalse($coordinator->actionRequired(), 'main view must not enqueue nested scout HITL');
        $this->assertSame(SubagentLiveStatusEnum::WaitingHuman, $state->subagentLiveCatalog->findByArtifactId('agent_scout')?->status);

        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            $scoutRun,
            'agent_scout',
            'scout',
            SubagentLiveStatusEnum::WaitingHuman,
            'pick file',
            1,
        ));

        $handler = new \Ineersa\Tui\Listener\RuntimeQuestionEventHandler();
        $handler->handleHumanInputRequested(
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
            $client,
            $coordinator,
            $state,
            $screen,
        );

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame($scoutRun, $active->runId);
        $this->assertFalse($active->transcript);
        $this->assertSame('Child agent scout asks', $active->header);

        $coordinator->answer('a.md');
        $this->assertNotNull($sent);
        $this->assertSame($scoutRun, $sent[0]);
        $this->assertSame('answer_human', $sent[1]->type);
    }

    public function testPollCatalogIngestSkipsSelectedActiveChildStream(): void
    {
        $parentRun = 'parent-1';
        $forkRun = 'fork-run-selected';
        $scoutRun = 'scout-run-bg';

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
                new RuntimeEvent('assistant_message', $forkRun, 10, ['text' => 'fork live only']),
            ],
            $scoutRun => [
                new RuntimeEvent(
                    'tool_execution_update',
                    $scoutRun,
                    3,
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
        ]);

        $poller = new SubagentLiveBackgroundChildPoller(new NullLogger());
        $poller->pollCatalogIngest($state, $client);

        $this->assertSame(0, $client->eventsCallCount[$forkRun] ?? 0);
        $this->assertSame(1, $client->eventsCallCount[$scoutRun] ?? 0);
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

/**
 * @implements AgentSessionClient
 */
final class BufferedChildEventsClient implements AgentSessionClient
{
    /** @var callable(string, UserCommand): void|null */
    public $onSend;

    /** @var array<string, int> */
    public array $eventsCallCount = [];

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
        $this->eventsCallCount[$runId] = ($this->eventsCallCount[$runId] ?? 0) + 1;

        return $this->eventsByRun[$runId] ?? [];
    }
}
