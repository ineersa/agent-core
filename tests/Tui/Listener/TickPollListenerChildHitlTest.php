<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\TestCase;

final class TickPollListenerChildHitlTest extends TestCase
{
    public function testChildHumanInputEnqueuesRunScopedRequestAndAnswersChildRun(): void
    {
        $childRunId = 'child-run-hitl-1';
        $sent = null;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(
            static function (string $runId, UserCommand $cmd) use (&$sent, $childRunId): void {
                $sent = [$runId, $cmd];
                self::assertSame($childRunId, $runId);
            },
        );

        $coordinator = new QuestionCoordinator();
        $state = new \Ineersa\Tui\Runtime\TuiSessionState('parent-1');
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            $childRunId,
            'agent_a',
            'scout',
            SubagentLiveStatusEnum::WaitingHuman,
            'task',
            1,
        ));

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $childRunId,
            seq: 3,
            payload: [
                'question_id' => 'q_child_1',
                'ui_kind' => 'confirm',
                'prompt' => 'Proceed?',
                'schema' => ['type' => 'boolean'],
            ],
        );
        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator, $state);

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame($childRunId, $active->runId);
        $this->assertSame(QuestionSource::AgentCore, $active->source);
        $this->assertSame('Child agent scout asks', $active->header);

        $coordinator->answer('yes');
        $this->assertNotNull($sent);
        $this->assertSame('answer_human', $sent[1]->type);
        $this->assertSame('q_child_1', $sent[1]->payload['question_id'] ?? null);
        $this->assertTrue($sent[1]->payload['answer'] ?? false);
    }

    public function testChildToolQuestionEnqueuesRunScopedRequestAndMarksNeedsInput(): void
    {
        $childRunId = 'child-run-tool-q-1';
        $sent = null;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(
            static function (string $runId, UserCommand $cmd) use (&$sent, $childRunId): void {
                $sent = [$runId, $cmd];
                self::assertSame($childRunId, $runId);
            },
        );

        $coordinator = new QuestionCoordinator();
        $state = new \Ineersa\Tui\Runtime\TuiSessionState('parent-1');
        $state->subagentLiveCatalog->ingestRuntimeEvent(new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: 'parent-1',
            seq: 1,
            payload: [
                'tool_call_id' => 'tc1',
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => [
                    'mode' => 'single',
                    'status' => 'running',
                    'agent_name' => 'scout',
                    'artifact_id' => 'agent_a',
                    'agent_run_id' => $childRunId,
                    'task_summary' => 'Task',
                ],
            ],
        ));
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            $childRunId,
            'agent_a',
            'scout',
            SubagentLiveStatusEnum::Running,
            'Task',
            1,
        ));
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;

        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-1',
            new PromptEditor(),
        );

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleToolQuestionRequested');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
            runId: $childRunId,
            seq: 0,
            payload: [
                'request_id' => 'rq_safe_1',
                'prompt' => 'Allow bash?',
                'kind' => 'confirm',
                'schema' => ['type' => 'boolean'],
                'tool_call_id' => 'tc_bash',
                'tool_name' => 'bash',
            ],
        );
        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator, $state, $screen);

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame($childRunId, $active->runId);
        $this->assertSame(QuestionSource::Tui, $active->source);
        $this->assertSame(QuestionKind::Confirm, $active->kind);

        $child = $state->subagentLiveCatalog->findByArtifactId('agent_a');
        $this->assertNotNull($child);
        $this->assertSame(SubagentLiveStatusEnum::WaitingHuman, $child->status);
        $this->assertSame(RunActivityStateEnum::WaitingHuman, $state->subagentLiveView->childActivity);

        $coordinator->answer('yes');
        $this->assertNotNull($sent);
        $this->assertSame('answer_tool_question', $sent[1]->type);
        $this->assertSame('rq_safe_1', $sent[1]->payload['request_id'] ?? null);
    }

    public function testToolTerminalDoesNotCancelParentQuestionWithSameToolCallId(): void
    {
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(
            new \Ineersa\Tui\Question\QuestionRequest(
                requestId: 'tool_parent',
                source: QuestionSource::Tui,
                kind: QuestionKind::Confirm,
                prompt: 'Parent?',
                schema: ['type' => 'boolean'],
                runId: 'parent-run',
                questionId: 'rq_parent',
                toolCallId: 'tc_shared',
                transcript: false,
            ),
        );

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleToolTerminal');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: 'child-run',
            seq: 1,
            payload: ['tool_call_id' => 'tc_shared'],
        );
        $controller = (new \ReflectionClass(\Ineersa\Tui\Question\QuestionController::class))->newInstanceWithoutConstructor();
        $ref->invoke($this->runtimeQuestionHandler(), $event, $coordinator, $controller);

        $this->assertTrue($coordinator->actionRequired(), 'Parent question must remain when child terminal event run id differs');
    }

    private function runtimeQuestionHandler(): RuntimeQuestionEventHandler
    {
        return new RuntimeQuestionEventHandler();
    }
}
