<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;

/**
 * Slice B: non-blocking tool-call human-input suspension via existing ToolCallResult.
 *
 * Contracts: (1) non-terminal envelope without result-store remember,
 * (2) batch admission identity/idempotency, (3) WaitingHuman + replay ToolCall request.
 */
final class ToolCallHumanInputSuspensionTest extends TestCase
{
    public function testWorkerDispatchesNonTerminalToolCallResultWithoutRemembering(): void
    {
        $toolbox = new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [];
            }

            public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
            {
                $request = PendingHumanInputRequestDTO::toolCallFromPayload(
                    payload: ['question_id' => 'req-1', 'prompt' => 'Allow?'],
                    continuationRef: [
                        'run_id' => 'run-susp',
                        'turn_no' => 2,
                        'step_id' => 'turn-2-tools-1',
                        'tool_call_id' => $toolCall->getId(),
                    ],
                );

                return new SymfonyToolResult($toolCall, new ToolExecutionHumanInputSuspension($request));
            }
        };

        $resultStore = new ToolExecutionResultStore();
        $bus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker(
            new ToolExecutor('parallel', 30, 2, $resultStore, toolbox: $toolbox),
            $bus,
            new InMemoryDeferredToolCompletionRepository(),
        );

        $worker(new ExecuteToolCall(
            runId: 'run-susp',
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-susp',
            toolCallId: 'call-susp',
            toolName: 'bash',
            args: ['command' => 'env'],
            orderIndex: 0,
        ));

        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $bus->messages[0]);
        /** @var ToolCallResult $envelope */
        $envelope = $bus->messages[0];
        $this->assertNotNull($envelope->pendingHumanInput);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $envelope->pendingHumanInput->continuationKind);
        $this->assertSame('req-1', $envelope->pendingHumanInput->questionId);
        $this->assertNull($resultStore->findByRunToolCall('run-susp', 'call-susp'));
    }

    public function testBatchAdmissionIsIdempotentAndFreesDispatchCapacity(): void
    {
        $collector = new ToolBatchCollector(defaultMaxParallelism: 1);
        $collector->registerExpectedBatch('run-b', 1, 'step-b', [
            $this->call('run-b', 'step-b', 'call-1', 0),
            $this->call('run-b', 'step-b', 'call-2', 1),
        ]);

        $effects = $collector->admitHumanInputSuspension('run-b', 1, 'step-b', 'call-1', 'q-1');
        $this->assertCount(1, $effects);
        $this->assertSame('call-2', $effects[0]->toolCallId);
        $this->assertSame([], $collector->admitHumanInputSuspension('run-b', 1, 'step-b', 'call-1', 'q-1'));

        $this->expectException(\LogicException::class);
        $collector->admitHumanInputSuspension('run-b', 1, 'step-b', 'call-1', 'q-other');
    }

    public function testHandlerAdmitsWaitingHumanAndReplayReconstructsToolCallRequest(): void
    {
        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch('run-h', 3, 'step-h', [
            $this->call('run-h', 'step-h', 'call-h', 0, turnNo: 3),
        ]);

        $request = PendingHumanInputRequestDTO::toolCallFromPayload(
            payload: ['question_id' => 'q-h', 'prompt' => 'Allow id?'],
            continuationRef: [
                'run_id' => 'run-h',
                'turn_no' => 3,
                'step_id' => 'step-h',
                'tool_call_id' => 'call-h',
            ],
        );
        $message = ToolCallResultFactory::fromExecuteToolCallAndHumanInputSuspension(
            $this->call('run-h', 'step-h', 'call-h', 0, turnNo: 3),
            new ToolExecutionHumanInputSuspension($request),
        );

        $handler = new ToolCallResultHandler(
            $collector,
            new EventFactory(),
            new ToolCallExtractor(),
            new AgentMessageNormalizer(),
        );
        $result = $handler->handle(
            $message,
            RunStateBuilder::running('run-h')
                ->withTurnNo(3)
                ->withLastSeq(5)
                ->withActiveStepId('step-h')
                ->withPendingToolCalls(['call-h' => false])
                ->build(),
        );

        $this->assertSame(RunStatus::WaitingHuman, $result->nextState?->status);
        $this->assertSame(['call-h' => false], $result->nextState?->pendingToolCalls);
        $this->assertSame([], $result->nextState?->messages);
        $pending = $result->nextState->pendingHumanInputRequests[0];
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $pending->continuationKind);
        $this->assertSame('tool_call', $pending->payload['continuation_kind'] ?? null);
        $this->assertSame('call-h', $pending->payload['continuation_ref']['tool_call_id'] ?? null);

        $waitingPayload = null;
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::WaitingHuman->value === $event->type) {
                $waitingPayload = $event->payload;
            }
        }
        $this->assertIsArray($waitingPayload);
        $this->assertSame('tool_call', $waitingPayload['continuation_kind'] ?? null);

        $replayed = (new RunStateReducer())->replay(
            RunState::queued('run-h'),
            [new RunEvent('run-h', 1, 3, RunEventTypeEnum::WaitingHuman->value, $waitingPayload)],
        );
        $this->assertSame(RunStatus::WaitingHuman, $replayed->status);
        $this->assertSame(HumanInputContinuationKindEnum::ToolCall, $replayed->pendingHumanInputRequests[0]->continuationKind);
        $this->assertSame('q-h', $replayed->pendingHumanInputRequests[0]->questionId);
    }

    private function call(
        string $runId,
        string $stepId,
        string $toolCallId,
        int $orderIndex,
        int $turnNo = 1,
    ): ExecuteToolCall {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: 'exec-'.$toolCallId,
            toolCallId: $toolCallId,
            toolName: 'bash',
            args: [],
            orderIndex: $orderIndex,
            mode: 'sequential',
            maxParallelism: 1,
        );
    }
}
