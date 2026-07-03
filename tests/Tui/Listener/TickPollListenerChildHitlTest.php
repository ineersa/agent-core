<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionSource;
use PHPUnit\Framework\TestCase;

final class TickPollListenerChildHitlTest extends TestCase
{
    public function testChildHumanInputEnqueuesRunScopedRequestAndAnswersChildRun(): void
    {
        $childRunId = 'child-run-hitl-1';
        $sent = null;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects(self::once())->method('send')->willReturnCallback(
            static function (string $runId, UserCommand $cmd) use (&$sent, $childRunId): void {
                $sent = [$runId, $cmd];
                self::assertSame($childRunId, $runId);
            },
        );

        $coordinator = new QuestionCoordinator();
        $state = new \Ineersa\Tui\Runtime\TuiSessionState('parent-1');
        $state->subagentLiveView->enter(new \Ineersa\Tui\Runtime\SubagentLiveChildDTO(
            $childRunId,
            'agent_a',
            'scout',
            \Ineersa\Tui\Runtime\SubagentLiveStatusEnum::WaitingHuman,
            'task',
            1,
        ));

        $ref = new \ReflectionMethod(TickPollListener::class, 'handleHumanInputRequested');
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
        $ref->invoke(null, $event, $client, $coordinator, $state);

        $active = $coordinator->activeRequest();
        self::assertNotNull($active);
        self::assertSame($childRunId, $active->runId);
        self::assertSame(QuestionSource::AgentCore, $active->source);
        self::assertSame('Subagent scout asks', $active->header);

        $coordinator->answer('yes');
        self::assertNotNull($sent);
        self::assertSame('answer_human', $sent[1]->type);
        self::assertSame('q_child_1', $sent[1]->payload['question_id'] ?? null);
        self::assertTrue($sent[1]->payload['answer'] ?? false);
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

        $ref = new \ReflectionMethod(TickPollListener::class, 'handleToolTerminal');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: 'child-run',
            seq: 1,
            payload: ['tool_call_id' => 'tc_shared'],
        );
        $controller = (new \ReflectionClass(\Ineersa\Tui\Question\QuestionController::class))->newInstanceWithoutConstructor();
        $ref->invoke(null, $event, $coordinator, $controller);

        self::assertTrue($coordinator->actionRequired(), 'Parent question must remain when child terminal event run id differs');
    }
}
