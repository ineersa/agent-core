<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ToolCallResultHandler implements RunMessageHandler
{
    public function __construct(
        private ToolBatchCollector $toolBatchCollector,
        private EventFactory $eventFactory,
        private ToolCallExtractor $toolCallExtractor,
        private AgentMessageNormalizer $messageNormalizer,
        private ?RunMetrics $metrics = null,
        private ?MessageBusInterface $commandBus = null,
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

        if (($state->turnNo !== $message->turnNo() || (null !== $state->activeStepId && $state->activeStepId !== $message->stepId()))
            || RunStatus::Cancelled === $state->status) {
            $nextState = $this->eventFactory->incrementStateVersion($state, eventCount: 1);
            $event = $this->eventFactory->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $state->turnNo,
                type: RunEventTypeEnum::StaleResultIgnored->value,
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
                    'type' => RunEventTypeEnum::StaleResultIgnored->value,
                    'payload' => [
                        'result' => 'tool_call_result',
                        'tool_call_id' => $message->toolCallId,
                        'step_id' => $message->stepId(),
                        'turn_no' => $message->turnNo(),
                        'status' => $state->status->value,
                    ],
                ],
                [
                    'type' => RunEventTypeEnum::AgentEnd->value,
                    'payload' => [
                        'reason' => 'cancelled',
                    ],
                ],
            ];

            $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);
            $nextState = new RunState(
                runId: $state->runId,
                status: RunStatus::Cancelled,
                version: $state->version + 1,
                turnNo: $state->turnNo,
                lastSeq: $state->lastSeq + \count($events),
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: $state->errorMessage,
                messages: $state->messages,
                activeStepId: $state->activeStepId,
                retryableFailure: false,
            );

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
            $nextState = $this->eventFactory->incrementStateVersion($state, eventCount: 1);
            $event = $this->eventFactory->event(
                runId: $runId,
                seq: $nextState->lastSeq,
                turnNo: $state->turnNo,
                type: RunEventTypeEnum::StaleResultIgnored->value,
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
                'type' => RunEventTypeEnum::ToolCallResultReceived->value,
                'payload' => [
                    'tool_call_id' => $message->toolCallId,
                    'order_index' => $message->orderIndex,
                    'is_error' => $message->isError,
                ],
            ],
            [
                'type' => RunEventTypeEnum::ToolExecutionEnd->value,
                'payload' => [
                    'tool_call_id' => $message->toolCallId,
                    'order_index' => $message->orderIndex,
                    'is_error' => $message->isError,
                    'result' => $this->extractResultText($message->result),
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
                $toolMsg = $this->messageNormalizer->toolMessage($orderedResult);
                $messages[] = $toolMsg;
                $toolMsgArray = $toolMsg->toArray();

                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::MessageStart->value,
                    'payload' => [
                        'message_role' => 'tool',
                        'tool_call_id' => $orderedResult->toolCallId,
                    ],
                ];

                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::MessageEnd->value,
                    'payload' => [
                        'message_role' => 'tool',
                        'tool_call_id' => $orderedResult->toolCallId,
                        'message' => $toolMsgArray,
                    ],
                ];

                $interruptPayload ??= $this->toolCallExtractor->interruptPayloadFromToolResult($orderedResult);
            }

            $eventSpecs[] = [
                'type' => RunEventTypeEnum::ToolBatchCommitted->value,
                'payload' => [
                    'count' => \count($outcome->orderedResults),
                ],
            ];

            $pendingToolCalls = [];

            if (null !== $interruptPayload) {
                $status = RunStatus::WaitingHuman;
                $eventSpecs[] = [
                    'type' => RunEventTypeEnum::WaitingHuman->value,
                    'payload' => $interruptPayload,
                ];
            }

            $postCommit = $this->turnCompletedCallbacks($runId, $state->turnNo);

            if (null === $interruptPayload) {
                $followUpAdvance = $this->followUpAdvanceCallback($runId);
                if (null !== $followUpAdvance) {
                    $postCommit[] = $followUpAdvance;
                }
            }
        }

        $events = $this->eventFactory->eventsFromSpecs($runId, $state->turnNo, $state->lastSeq + 1, $eventSpecs);

        $nextState = new RunState(
            runId: $state->runId,
            status: $status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + \count($events),
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: $pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $messages,
            activeStepId: $state->activeStepId,
            retryableFailure: false,
        );

        return new HandlerResult(
            nextState: $nextState,
            events: $events,
            postCommitEffects: $effects,
            postCommit: $postCommit,
        );
    }

    private function followUpAdvanceCallback(string $runId): ?callable
    {
        if (null === $this->commandBus) {
            return null;
        }

        return function () use ($runId): void {
            $stepId = \sprintf('advance-after-tools-%d', hrtime(true));

            try {
                $this->commandBus->dispatch(new AdvanceRun(
                    runId: $runId,
                    turnNo: 0,
                    stepId: $stepId,
                    attempt: 1,
                    idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $stepId)),
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch AdvanceRun after tool batch completion.', previous: $exception);
            }
        };
    }

    /**
     * Extract a displayable result string from a tool result payload.
     *
     * The result array carries 'content' as an array of content parts
     * (Symfony AI format).  This helper collects text from parts where
     * type === 'text' and joins them, so the string propagates through
     * RuntimeEventTranslator to the projection layer and surfaces as
     * actual tool output in the TUI.
     *
     * Returns '' for non-array results, missing/unexpected structures,
     * or when no text parts are found.  The downstream projector falls
     * back to '{tool_name} completed' when the result is empty, so
     * tools that produce no displayable output still show a sensible
     * status.
     *
     * @param array<string, mixed>|string|int|float|bool|null $result
     */
    private function extractResultText(mixed $result): string
    {
        if (!\is_array($result)) {
            return '';
        }

        $content = $result['content'] ?? null;
        if (!\is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if (($part['type'] ?? null) !== 'text') {
                continue;
            }
            $text = $part['text'] ?? null;
            if (!\is_string($text)) {
                continue;
            }
            $parts[] = $text;
        }

        return implode("\n", $parts);
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
