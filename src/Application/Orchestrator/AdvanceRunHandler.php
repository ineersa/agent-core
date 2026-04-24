<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class AdvanceRunHandler implements RunMessageHandler
{
    public function __construct(
        private CommandMailboxPolicy $commandMailboxPolicy,
        private RunMessageStateTools $stateTools,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof AdvanceRun;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof AdvanceRun) {
            throw new \InvalidArgumentException('AdvanceRunHandler can only handle AdvanceRun messages.');
        }

        $runId = $message->runId();

        [$preparedState, $boundaryEventSpecs] = null === $this->tracer
            ? $this->commandMailboxPolicy->applyPendingTurnStartCommands($state)
            : $this->tracer->inSpan('command.application.turn_start_boundary', [
                'run_id' => $runId,
                'turn_no' => $state->turnNo,
                'step_id' => $state->activeStepId,
            ], fn (): array => $this->commandMailboxPolicy->applyPendingTurnStartCommands($state))
        ;

        if (RunStatus::Cancelling === $preparedState->status) {
            $eventSpecs = [
                ...$boundaryEventSpecs,
                [
                    'type' => CoreLifecycleEventType::AGENT_END,
                    'payload' => ['reason' => 'cancelled'],
                ],
            ];

            $events = $this->stateTools->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = new RunState(
                runId: $preparedState->runId,
                status: RunStatus::Cancelled,
                version: $state->version + 1,
                turnNo: $preparedState->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: $preparedState->errorMessage,
                messages: $preparedState->messages,
                activeStepId: $preparedState->activeStepId,
                retryableFailure: false,
            );

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
            );
        }

        if (\in_array($preparedState->status, [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::WaitingHuman], true)) {
            if ([] === $boundaryEventSpecs) {
                return new HandlerResult();
            }

            $events = $this->stateTools->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $boundaryEventSpecs);
            $nextState = new RunState(
                runId: $preparedState->runId,
                status: $preparedState->status,
                version: $state->version + 1,
                turnNo: $preparedState->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: $preparedState->isStreaming,
                streamingMessage: $preparedState->streamingMessage,
                pendingToolCalls: $preparedState->pendingToolCalls,
                errorMessage: $preparedState->errorMessage,
                messages: $preparedState->messages,
                activeStepId: $preparedState->activeStepId,
                retryableFailure: $preparedState->retryableFailure,
            );

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
            );
        }

        $nextTurnNo = $preparedState->turnNo + 1;
        $nextStepId = $message->stepId();

        $effect = new ExecuteLlmStep(
            runId: $runId,
            turnNo: $nextTurnNo,
            stepId: $nextStepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|llm|%d|%s', $runId, $nextTurnNo, $nextStepId)),
            contextRef: \sprintf('hot:run:%s', $runId),
            toolsRef: \sprintf('toolset:run:%s:turn:%d', $runId, $nextTurnNo),
        );

        $eventSpecs = [
            ...$boundaryEventSpecs,
            [
                'type' => 'turn_advanced',
                'turn_no' => $nextTurnNo,
                'payload' => [
                    'step_id' => $nextStepId,
                    'turn_no' => $nextTurnNo,
                ],
            ],
        ];

        $events = $this->stateTools->eventsFromSpecs($runId, $preparedState->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = new RunState(
            runId: $preparedState->runId,
            status: RunStatus::Running,
            version: $state->version + 1,
            turnNo: $nextTurnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $preparedState->pendingToolCalls,
            errorMessage: $preparedState->errorMessage,
            messages: $preparedState->messages,
            activeStepId: $nextStepId,
            retryableFailure: false,
        );

        $postCommit = [];
        if (null !== $this->metrics) {
            $postCommit[] = function () use ($runId, $nextTurnNo): void {
                $this->metrics->recordTurnStarted($runId, $nextTurnNo);
            };
        }

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            effects: [$effect],
            postCommit: $postCommit,
        );
    }
}
