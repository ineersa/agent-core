<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps tool-call streaming deltas to tool call transient events.
 *
 * ToolCallStart → tool_call.started.
 * ToolInputDelta → tool_call.arguments_delta.
 * ToolCallComplete → tool_call.arguments_completed (one per ToolCall).
 *
 * Events are emitted both to the runtime event sink (in-process) and
 * the StdoutRuntimeEventSink (cross-process via LLM consumer stdout pipe
 * in async mode).
 *
 * @internal
 */
final class ToolCallStreamSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RuntimeEventSinkInterface $sink,
        private readonly ?RuntimeEventSinkInterface $stdoutSink = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ToolCallStart::class => 'onToolCallStart',
            ToolInputDelta::class => 'onToolInputDelta',
            ToolCallComplete::class => 'onToolCallComplete',
        ];
    }

    public function onToolCallStart(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof ToolCallStart);
        $event->handled = true;

        // The durable converter already suppresses empty-id starts at the
        // conversion layer.  Keep this guard as defense-in-depth in case
        // a future provider path bypasses DurableResultConverter.
        if ('' === $delta->getId()) {
            return;
        }

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::ToolCallStarted,
            [
                'tool_call_id' => $delta->getId(),
                'tool_name' => $delta->getName(),
            ],
            \sprintf('tool_call_%s', $delta->getId()),
        );
    }

    public function onToolInputDelta(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof ToolInputDelta);
        $event->handled = true;

        // Suppress argument deltas with empty tool-call ids.
        // These can occur when the provider sends argument chunks at
        // a stream index that never received an id-bearing chunk.
        // The durable converter prevents these at the conversion layer;
        // this guard is defense-in-depth.
        if ('' === $delta->getId()) {
            return;
        }

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::ToolCallArgumentsDelta,
            [
                'tool_call_id' => $delta->getId(),
                'tool_name' => $delta->getName(),
                'partial_json' => $delta->getPartialJson(),
            ],
            \sprintf('tool_call_%s', $delta->getId()),
        );
    }

    public function onToolCallComplete(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof ToolCallComplete);
        $event->handled = true;

        foreach ($delta->getToolCalls() as $toolCall) {
            $this->emit(
                $event->runId, $event->stepId,
                RuntimeEventTypeEnum::ToolCallArgumentsCompleted,
                [
                    'tool_call_id' => $toolCall->getId(),
                    'tool_name' => $toolCall->getName(),
                    'arguments' => $toolCall->getArguments(),
                ],
                \sprintf('tool_call_%s', $toolCall->getId()),
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emit(
        string $runId,
        ?string $stepId,
        RuntimeEventTypeEnum $type,
        array $payload = [],
        ?string $blockId = null,
    ): void {
        $merged = $payload;

        if (null !== $stepId) {
            $merged['step_id'] = $stepId;
        }

        if (null !== $blockId) {
            $merged['block_id'] = $blockId;
        }

        $event = new RuntimeEvent(
            type: $type->value,
            runId: $runId,
            seq: 0,
            payload: $merged,
        );

        $this->sink->emit($event);
        $this->stdoutSink?->emit($event);
    }
}
