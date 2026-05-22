<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\RuntimeEventPublisherInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingStart;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps thinking streaming deltas (ThinkingStart, ThinkingDelta,
 * ThinkingComplete) to assistant thinking transient events.
 *
 * ThinkingStart → assistant.thinking_started.
 * ThinkingDelta → assistant.thinking_delta (with implicit start if needed).
 * ThinkingComplete → assistant.thinking_completed.
 * ThinkingSignature → silently skipped.
 *
 * Resets per-stream state on llm_stream.start.
 *
 * Events are emitted both to the runtime event sink (in-process) and
 * the runtime event publisher (cross-process via Messenger in async mode).
 */
final class AssistantThinkingStreamSubscriber implements EventSubscriberInterface
{
    private bool $thinkingStarted = false;

    public function __construct(
        private readonly RuntimeEventSinkInterface $sink,
        private readonly ?RuntimeEventPublisherInterface $runtimeEventPublisher = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LlmStreamDispatchObserver::EVENT_START => 'onStreamStart',
            ThinkingStart::class => 'onThinkingStart',
            ThinkingDelta::class => 'onThinkingDelta',
            ThinkingComplete::class => 'onThinkingComplete',
        ];
    }

    public function onStreamStart(): void
    {
        $this->thinkingStarted = false;
    }

    public function onThinkingStart(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $this->thinkingStarted = true;
        $event->handled = true;

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::AssistantThinkingStarted,
            [],
            $this->blockId($event->runId, $event->stepId, 'thinking'),
        );
    }

    public function onThinkingDelta(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof ThinkingDelta);
        $event->handled = true;

        if (!$this->thinkingStarted) {
            $this->thinkingStarted = true;

            $this->emit(
                $event->runId, $event->stepId,
                RuntimeEventTypeEnum::AssistantThinkingStarted,
                [],
                $this->blockId($event->runId, $event->stepId, 'thinking'),
            );
        }

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::AssistantThinkingDelta,
            ['thinking' => $delta->getThinking()],
            $this->blockId($event->runId, $event->stepId, 'thinking'),
        );
    }

    public function onThinkingComplete(RuntimeStreamDeltaEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof ThinkingComplete);
        $event->handled = true;

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::AssistantThinkingCompleted,
            [
                'thinking' => $delta->getThinking(),
                'signature' => $delta->getSignature(),
            ],
            $this->blockId($event->runId, $event->stepId, 'thinking'),
        );
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
