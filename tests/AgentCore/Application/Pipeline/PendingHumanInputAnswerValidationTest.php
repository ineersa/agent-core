<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CommandHandlerRegistry;
use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

/**
 * Slice A contracts: replay retains typed request; mismatched answer rejects;
 * matching model-turn answer clears request and schedules AdvanceRun.
 */
final class PendingHumanInputAnswerValidationTest extends TestCase
{
    public function testWaitingHumanReplayRetainsTypedActiveModelTurnRequest(): void
    {
        $payload = [
            'kind' => 'interrupt',
            'question_id' => 'ah_q1',
            'prompt' => 'Approve the change?',
            'schema' => ['type' => 'boolean'],
            'tool_call_id' => 'tc-ask-1',
            'tool_name' => 'ask_human',
            'ui_kind' => 'confirm',
        ];
        $runId = 'run-pending-hitl-replay';
        $state = (new RunStateReducer())->replay(RunState::queued($runId), [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, ['step_id' => 'start', 'messages' => []]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::WaitingHuman->value, $payload),
        ]);

        $this->assertSame(RunStatus::WaitingHuman, $state->status);
        $this->assertCount(1, $state->pendingHumanInputRequests);
        $request = $state->pendingHumanInputRequests[0];
        $this->assertSame('ah_q1', $request->questionId);
        $this->assertSame(HumanInputContinuationKindEnum::ModelTurn, $request->continuationKind);
        $this->assertSame($payload, $request->payload);
    }

    public function testMismatchedQuestionIdIsRejected(): void
    {
        $result = $this->applyHandler()->handle(
            $this->humanResponse('run-hitl-mismatch', 'ah_stale', 'nope'),
            $this->waitingState('run-hitl-mismatch', 'ah_expected'),
        );

        $this->assertSame(RunStatus::WaitingHuman, $result->nextState?->status);
        $this->assertSame('ah_expected', $result->nextState?->pendingHumanInputRequests[0]->questionId);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandRejected->value, $result->events[0]->type);
        $this->assertStringContainsString('question_id', (string) $result->nextState?->errorMessage);
        $this->assertSame([], $result->postCommit);
    }

    public function testMatchingModelTurnAnswerClearsRequestAndSchedulesAdvance(): void
    {
        $bus = new TestMessageBus();
        $result = $this->applyHandler($bus)->handle(
            $this->humanResponse('run-hitl-ok', 'ah_ok', 'yes proceed'),
            $this->waitingState('run-hitl-ok', 'ah_ok'),
        );

        $this->assertSame(RunStatus::Running, $result->nextState?->status);
        $this->assertSame([], $result->nextState?->pendingHumanInputRequests);
        $this->assertSame('user', $result->nextState?->messages[0]->role);
        $this->assertStringContainsString('yes proceed', (string) ($result->nextState?->messages[0]->content[0]['text'] ?? ''));
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::AgentCommandApplied->value, $result->events[0]->type);
        $this->assertSame('ah_ok', $result->events[0]->payload['question_id'] ?? null);
        foreach ($result->postCommit as $callback) {
            $callback();
        }
        $this->assertInstanceOf(AdvanceRun::class, $bus->messages[0] ?? null);
        $this->assertSame('run-hitl-ok', $bus->messages[0]->runId());
    }

    private function waitingState(string $runId, string $questionId): RunState
    {
        return RunStateBuilder::running($runId)
            ->withStatus(RunStatus::WaitingHuman)
            ->withVersion(4)
            ->withTurnNo(1)
            ->withLastSeq(8)
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
                    'question_id' => $questionId,
                    'prompt' => 'Continue?',
                    'schema' => ['type' => 'string'],
                ]),
            ])
            ->build();
    }

    private function humanResponse(string $runId, string $questionId, string $answer): ApplyCommand
    {
        return new ApplyCommand(
            runId: $runId,
            turnNo: 1,
            stepId: 'human-step',
            attempt: 1,
            idempotencyKey: 'human-'.$questionId,
            kind: CoreCommandKind::HumanResponse,
            payload: ['question_id' => $questionId, 'answer' => $answer],
        );
    }

    private function applyHandler(?TestMessageBus $bus = null): ApplyCommandHandler
    {
        $store = new InMemoryCommandStore();
        $router = new CommandRouter(new CommandHandlerRegistry([]));

        return new ApplyCommandHandler(
            commandStore: $store,
            commandRouter: $router,
            commandMailboxPolicy: new CommandMailboxPolicy($store, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            commandBus: $bus,
        );
    }
}
