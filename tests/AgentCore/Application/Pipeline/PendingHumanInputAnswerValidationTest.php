<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: ask_human interrupt commits exactly one model-turn pending
 * request; answer_human rejects mismatched question_id; matching answer clears
 * the request, appends the human message, returns to Running, and schedules
 * AdvanceRun. Protects the durable request identity contract for canonical HITL.
 */
final class PendingHumanInputAnswerValidationTest extends TestCase
{
    public function testAskHumanInterruptPopulatesExactlyOneModelTurnRequest(): void
    {
        $collector = new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector();
        $collector->registerExpectedBatch(
            runId: 'run-hitl-populate',
            turnNo: 1,
            stepId: 'turn-1',
            toolCalls: [
                new ExecuteToolCall(
                    runId: 'run-hitl-populate',
                    turnNo: 1,
                    stepId: 'turn-1',
                    attempt: 1,
                    idempotencyKey: 'exec-ask',
                    toolCallId: 'tc-ask',
                    toolName: 'ask_human',
                    args: ['prompt' => 'Ship it?'],
                    orderIndex: 0,
                    maxParallelism: 1,
                ),
            ],
        );

        $handler = new ToolCallResultHandler(
            toolBatchCollector: $collector,
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
        );

        $state = RunStateBuilder::running('run-hitl-populate')
            ->withVersion(2)
            ->withTurnNo(1)
            ->withLastSeq(4)
            ->withPendingToolCalls(['tc-ask' => false])
            ->withActiveStepId('turn-1')
            ->build();

        $message = ToolCallResultBuilder::success('run-hitl-populate')
            ->withTurnNo(1)
            ->withStepId('turn-1')
            ->withIdempotencyKey('result-ask')
            ->withToolCallId('tc-ask')
            ->withOrderIndex(0)
            ->withResult([
                'tool_name' => 'ask_human',
                'content' => [['type' => 'text', 'text' => 'waiting']],
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'ah_ship',
                    'prompt' => 'Ship it?',
                    'schema' => ['type' => 'boolean'],
                    'ui_kind' => 'confirm',
                ],
            ])
            ->build();

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::WaitingHuman, $result->nextState->status);
        $this->assertCount(1, $result->nextState->pendingHumanInputRequests);
        $request = $result->nextState->pendingHumanInputRequests[0];
        $this->assertSame('ah_ship', $request->questionId);
        $this->assertSame(HumanInputContinuationKindEnum::ModelTurn, $request->continuationKind);
        $this->assertSame('Ship it?', $request->prompt);
        $this->assertSame(['type' => 'boolean'], $request->schema);
        $this->assertSame('tc-ask', $request->toolCallId);
        $this->assertSame('ask_human', $request->toolName);

        $waitingEvents = array_values(array_filter(
            $result->events,
            static fn ($event) => RunEventTypeEnum::WaitingHuman->value === $event->type,
        ));
        $this->assertCount(1, $waitingEvents);
        $this->assertSame('ah_ship', $waitingEvents[0]->payload['question_id'] ?? null);
        $this->assertSame('confirm', $waitingEvents[0]->payload['ui_kind'] ?? null);
    }

    public function testMismatchedQuestionIdIsRejected(): void
    {
        $handler = $this->createApplyHandler();

        $pending = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
            'question_id' => 'ah_expected',
            'prompt' => 'Continue?',
            'schema' => ['type' => 'string'],
            'tool_call_id' => 'tc-1',
            'tool_name' => 'ask_human',
        ]);

        $state = RunStateBuilder::running('run-hitl-mismatch')
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(4)
            ->withTurnNo(1)
            ->withLastSeq(8)
            ->withPendingHumanInputRequests([$pending])
            ->build();

        $message = new ApplyCommand(
            runId: 'run-hitl-mismatch',
            turnNo: 1,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-mismatch',
            kind: CoreCommandKind::HumanResponse,
            payload: [
                'question_id' => 'ah_stale',
                'answer' => 'nope',
            ],
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::WaitingHuman, $result->nextState->status);
        $this->assertCount(1, $result->nextState->pendingHumanInputRequests);
        $this->assertSame('ah_expected', $result->nextState->pendingHumanInputRequests[0]->questionId);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandRejected->value, $result->events[0]->type);
        $this->assertStringContainsString('question_id', (string) $result->nextState->errorMessage);
        $this->assertSame([], $result->postCommit);
    }

    public function testMatchingModelTurnAnswerClearsRequestAndSchedulesAdvance(): void
    {
        $commandBus = new TestMessageBus();
        $handler = $this->createApplyHandler($commandBus);

        $pending = \Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
            'question_id' => 'ah_ok',
            'prompt' => 'Continue?',
            'schema' => ['type' => 'string'],
            'tool_call_id' => 'tc-ok',
            'tool_name' => 'ask_human',
        ]);

        $state = RunStateBuilder::running('run-hitl-ok')
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(5)
            ->withTurnNo(2)
            ->withLastSeq(10)
            ->withPendingHumanInputRequests([$pending])
            ->build();

        $message = new ApplyCommand(
            runId: 'run-hitl-ok',
            turnNo: 2,
            stepId: 'human-ok',
            attempt: 1,
            idempotencyKey: 'human-ok',
            kind: CoreCommandKind::HumanResponse,
            payload: [
                'question_id' => 'ah_ok',
                'answer' => 'yes proceed',
            ],
        );

        $result = $handler->handle($message, $state);

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status);
        $this->assertSame([], $result->nextState->pendingHumanInputRequests);
        $this->assertCount(1, $result->nextState->messages);
        $this->assertSame('user', $result->nextState->messages[0]->role);
        $this->assertStringContainsString('yes proceed', (string) ($result->nextState->messages[0]->content[0]['text'] ?? ''));
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandApplied->value, $result->events[0]->type);
        $this->assertSame('ah_ok', $result->events[0]->payload['question_id'] ?? null);
        $this->assertNotEmpty($result->postCommit);

        foreach ($result->postCommit as $callback) {
            $callback();
        }
        $this->assertNotEmpty($commandBus->dispatched);
        $advance = $commandBus->dispatched[0];
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\AdvanceRun::class, $advance);
        $this->assertSame('run-hitl-ok', $advance->runId());
    }

    private function createApplyHandler(?TestMessageBus $commandBus = null): ApplyCommandHandler
    {
        $commandStore = new InMemoryCommandStore();
        $commandRouter = new CommandRouter(new CommandHandlerRegistry([]));
        $commandMailboxPolicy = new CommandMailboxPolicy(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
        );

        return new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $commandRouter,
            commandMailboxPolicy: $commandMailboxPolicy,
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $commandBus,
        );
    }
}
