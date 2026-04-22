<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;

final readonly class ToolCallResultHandler implements RunMessageHandler
{
    public function __construct(
        private ToolBatchCollector $toolBatchCollector,
        private RunMessageStateTools $stateTools,
        private ?RunMetrics $metrics = null,
    ) {
    }

    public function supports(object $message): bool
    {
        return $message instanceof ToolCallResult;
    }

    public function handle(object $message, RunState $state): HandlerResult
    {
        if (!$message instanceof ToolCallResult) {
            throw new \InvalidArgumentException('ToolCallResultHandler can only handle ToolCallResult messages.');
        }

        $runId = $message->runId();

        if ($this->stateTools->isStaleResult($state, $message->turnNo(), $message->stepId())
            || RunStatus::Cancelled === $state->status) {
            $nextState = $this->stateTools->incrementStateVersion($state, eventCount: 1);
            $event = $this->stateTools->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $state->turnNo,
                type: 'stale_result_ignored',
                payload: [
                    'result' => 'tool_call_result',
                    'tool_call_id' => $message->toolCallId,
                    'step_id' => $message->stepId(),
                    'turn_no' => $message->turnNo(),
                    'status' => $state->status->value,
                ],
            );

            return new HandlerResult(
                nextState: $nextState,
                events: [$event],
            );
        }

        if (RunStatus::Cancelling === $state->status) {
            $eventSpecs = [
                [
                    'type' => 'stale_result_ignored',
                    'payload' => [
                        'result' => 'tool_call_result',
                        'tool_call_id' => $message->toolCallId,
                        'step_id' => $message->stepId(),
                        'turn_no' => $message->turnNo(),
                        'status' => $state->status->value,
                    ],
                ],
                [
                    'type' => CoreLifecycleEventType::AGENT_END,
                    'payload' => [
                        'reason' => 'cancelled',
                    ],
                ],
            ];

            $events = $this->stateTools->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = $this->stateTools->copyState($state, [
                'status' => RunStatus::Cancelled,
                'version' => $state->version + 1,
                'lastSeq' => $state->lastSeq + \count($events),
                'pendingToolCalls' => [],
                'isStreaming' => false,
                'streamingMessage' => null,
                'retryableFailure' => false,
            ]);

            return new HandlerResult(
                nextState: $nextState,
                events: $events,
                postCommit: $this->turnCompletedCallbacks($runId, $state->turnNo),
            );
        }

        $outcome = $this->toolBatchCollector->collect($message);
        if ($outcome->duplicate) {
            return new HandlerResult();
        }

        if (!$outcome->accepted) {
            $nextState = $this->stateTools->incrementStateVersion($state, eventCount: 1);
            $event = $this->stateTools->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $state->turnNo,
                type: 'stale_result_ignored',
                payload: [
                    'result' => 'tool_call_result',
                    'tool_call_id' => $message->toolCallId,
                    'reason' => 'untracked_tool_call',
                ],
            );

            return new HandlerResult(
                nextState: $nextState,
                events: [$event],
            );
        }

        $eventSpecs = [
            [
                'type' => 'tool_call_result_received',
                'payload' => [
                    'tool_call_id' => $message->toolCallId,
                    'order_index' => $message->orderIndex,
                    'is_error' => $message->isError,
                ],
            ],
            [
                'type' => CoreLifecycleEventType::TOOL_EXECUTION_END,
                'payload' => [
                    'tool_call_id' => $message->toolCallId,
                    'order_index' => $message->orderIndex,
                    'is_error' => $message->isError,
                ],
            ],
        ];

        $pendingToolCalls = $state->pendingToolCalls;
        if (\array_key_exists($message->toolCallId, $pendingToolCalls)) {
            $pendingToolCalls[$message->toolCallId] = true;
        }

        $messages = $state->messages;
        $effects = $outcome->effectsToDispatch;
        $status = RunStatus::Running;

        $postCommit = [];

        if ($outcome->complete) {
            $interruptPayload = null;

            foreach ($outcome->orderedResults as $orderedResult) {
                $messages[] = $this->stateTools->toolMessage($orderedResult);

                $eventSpecs[] = [
                    'type' => CoreLifecycleEventType::MESSAGE_START,
                    'payload' => [
                        'message_role' => 'tool',
                        'tool_call_id' => $orderedResult->toolCallId,
                    ],
                ];

                $eventSpecs[] = [
                    'type' => CoreLifecycleEventType::MESSAGE_END,
                    'payload' => [
                        'message_role' => 'tool',
                        'tool_call_id' => $orderedResult->toolCallId,
                    ],
                ];

                $interruptPayload ??= $this->stateTools->interruptPayloadFromToolResult($orderedResult);
            }

            $eventSpecs[] = [
                'type' => 'tool_batch_committed',
                'payload' => [
                    'count' => \count($outcome->orderedResults),
                ],
            ];

            $pendingToolCalls = [];

            if (null !== $interruptPayload) {
                $status = RunStatus::WaitingHuman;
                $eventSpecs[] = [
                    'type' => 'waiting_human',
                    'payload' => $interruptPayload,
                ];
            }

            $postCommit = $this->turnCompletedCallbacks($runId, $state->turnNo);
        }

        $events = $this->stateTools->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = $this->stateTools->copyState($state, [
            'status' => $status,
            'version' => $state->version + 1,
            'lastSeq' => $state->lastSeq + \count($events),
            'isStreaming' => false,
            'streamingMessage' => null,
            'pendingToolCalls' => $pendingToolCalls,
            'messages' => $messages,
            'retryableFailure' => false,
        ]);

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            postCommitEffects: $effects,
            postCommit: $postCommit,
        );
    }

    /**
     * @return list<callable(): void>
     */
    private function turnCompletedCallbacks(string $runId, int $turnNo): array
    {
        if (null === $this->metrics) {
            return [];
        }

        return [function () use ($runId, $turnNo): void {
            $this->metrics->recordTurnCompleted($runId, $turnNo);
        }];
    }
}
