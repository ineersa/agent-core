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
