<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\RuntimeEventPublisherInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps TextDelta streaming deltas to assistant text transient events.
 *
 * First TextDelta → assistant.text_started (with block_id).
 * Subsequent TextDelta values → assistant.text_delta.
 *
 * Resets per-stream state on llm_stream.start.
 *
 * Events are emitted both to the runtime event sink (in-process) and
 * the runtime event publisher (cross-process via Messenger in async mode).
 */
final class AssistantTextStreamSubscriber implements EventSubscriberInterface
{
    private bool $textStarted = false;

    public function __construct(
        private readonly RuntimeEventSinkInterface $sink,
        private readonly ?RuntimeEventPublisherInterface $runtimeEventPublisher = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LlmStreamDispatchObserver::EVENT_START => 'onStreamStart',
            TextDelta::class => 'onTextDelta',
        ];
    }

    public function onStreamStart(RuntimeStreamLifecycleEvent $event): void
    {
        $this->textStarted = false;

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::AssistantMessageStarted,
            [],
        );
    }

    public function onTextDelta(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof TextDelta);
        $text = $delta->getText();

        if (!$this->textStarted) {
            $this->textStarted = true;
            $event->handled = true;

            $this->emit(
                $event->runId, $event->stepId,
                RuntimeEventTypeEnum::AssistantTextStarted,
                ['text' => $text],
                $this->blockId($event->runId, $event->stepId, 'text'),
            );
        } else {
            $event->handled = true;

            $this->emit(
                $event->runId, $event->stepId,
                RuntimeEventTypeEnum::AssistantTextDelta,
                ['text' => $text],
                $this->blockId($event->runId, $event->stepId, 'text'),
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
        $this->runtimeEventPublisher?->publishEvent($event);
    }

    private function blockId(string $runId, ?string $stepId, string $kind): string
    {
        if (null !== $stepId) {
            return \sprintf('%s_%s_%s', $runId, $stepId, $kind);
        }

        return \sprintf('%s_%s', $runId, $kind);
    }
}
