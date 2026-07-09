<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;

/** @covers \Ineersa\Tui\Listener\RuntimeQuestionEventHandler */
final class RuntimeQuestionEventHandlerTest extends TestCase
{
    public function testNestedChildHumanInputUsesChildHeaderAndDoesNotProjectToParentTranscript(): void
    {
        $parentRun = 'parent-main';
        $scoutRun = 'scout-child';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
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

        $coordinator = new QuestionCoordinator();
        $client = new class implements AgentSessionClient {
            public function start(StartRunRequest $request): RunHandle
            {
                throw new \BadMethodCallException();
            }

            public function send(string $runId, UserCommand $command): void
            {
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
                return [];
            }
        };

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $parentRun,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );

        $handler = new RuntimeQuestionEventHandler();
        $handler->handleHumanInputRequested(
            new RuntimeEvent(
                RuntimeEventTypeEnum::HumanInputRequested->value,
                $scoutRun,
                2,
                [
                    'question_id' => 'q1',
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

        $this->assertFalse($coordinator->actionRequired());
        $this->assertNull($coordinator->activeRequest());
        $this->assertSame(SubagentLiveStatusEnum::Running, $state->subagentLiveCatalog->findByArtifactId('agent_scout')?->status);
    }


    public function testChildToolQuestionOnMainViewDoesNotEnqueueCoordinator(): void
    {
        $parentRun = 'parent-main';
        $scoutRun = 'scout-child-tool';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
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

        $coordinator = new QuestionCoordinator();
        $client = $this->noopClient();
        $screen = $this->chatScreen($parentRun);

        $handler = new RuntimeQuestionEventHandler();
        $handler->handleToolQuestionRequested(
            new RuntimeEvent(
                RuntimeEventTypeEnum::ToolQuestionRequested->value,
                $scoutRun,
                2,
                [
                    'request_id' => 'rq1',
                    'prompt' => 'Allow bash?',
                    'kind' => 'confirm',
                    'schema' => ['type' => 'boolean'],
                    'tool_call_id' => 'tc1',
                    'tool_name' => 'bash',
                ],
            ),
            $client,
            $coordinator,
            $state,
            $screen,
        );

        $this->assertFalse($coordinator->actionRequired());
        $this->assertNull($coordinator->activeRequest());
        $this->assertSame(SubagentLiveStatusEnum::WaitingHuman, $state->subagentLiveCatalog->findByArtifactId('agent_scout')?->status);
    }

    public function testLiveViewSelectedChildHumanInputMatchesDirectSubagentOwnership(): void
    {
        $parentRun = 'parent-main';
        $scoutRun = 'scout-child-live';

        $state = new TuiSessionState($parentRun);
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            'tool_execution_update',
            $parentRun,
            1,
            [
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'waiting_human',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_scout',
                    'agent_run_id' => $scoutRun,
                    'task_summary' => 'pick file',
                ],
            ],
        ));
        $state->subagentLiveView->enter(new \Ineersa\Tui\Runtime\SubagentLiveChildDTO(
            $scoutRun,
            'agent_scout',
            'scout',
            SubagentLiveStatusEnum::WaitingHuman,
            'pick file',
            1,
        ));

        $coordinator = new QuestionCoordinator();
        $client = $this->noopClient();
        $screen = $this->chatScreen($parentRun);

        $handler = new RuntimeQuestionEventHandler();
        $handler->handleChildAgentHumanInputRequested(
            new RuntimeEvent(
                RuntimeEventTypeEnum::HumanInputRequested->value,
                $scoutRun,
                2,
                [
                    'question_id' => 'q_live',
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
    }

    private function noopClient(): AgentSessionClient
    {
        return new class implements AgentSessionClient {
            public function start(StartRunRequest $request): RunHandle
            {
                throw new \BadMethodCallException();
            }

            public function send(string $runId, UserCommand $command): void
            {
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
                return [];
            }
        };
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
