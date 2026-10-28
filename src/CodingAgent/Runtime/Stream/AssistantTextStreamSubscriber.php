<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\AssistantMessageContentMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Result\Stream\Delta\CommentaryDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps text and commentary deltas to assistant text transient events.
 *
 * Native message boundaries split commentary from answers. Signed completions
 * replace streamed text and supply terminal-only messages without duplication.
 * Providers without native boundaries keep one text block per step.
 * Resets per-stream state on llm_stream.start.
 *
 * Events use the in-process sink in TUI mode or the controller pipe in an
 * async consumer. They must not be copied into the undrained in-process queue
 * from a long-lived messenger worker.
 *
 * @internal
 */
final class AssistantTextStreamSubscriber implements EventSubscriberInterface
{
    /** @var array<string, array{textStarted: bool, activeMessage: array<string, mixed>|null, messages: array<string, array<string, mixed>>}> */
    private array $streams = [];

    /**
     * @param RuntimeEventSinkInterface  $sink       in-process sink (always available)
     * @param ?RuntimeEventSinkInterface $stdoutSink STDOUT pipe sink for LLM consumer (nullable:
     *                                               in in-process/test mode there is no STDOUT pipe; the sink auto-detects TTY and returns early)
     */
    public function __construct(
        private readonly RuntimeEventSinkInterface $sink,
        private readonly ?RuntimeEventSinkInterface $stdoutSink = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LlmStreamDispatchObserver::EVENT_START => 'onStreamStart',
            LlmStreamDispatchObserver::EVENT_END => 'onStreamTerminal',
            LlmStreamDispatchObserver::EVENT_ERROR => 'onStreamTerminal',
            TextDelta::class => 'onTextDelta',
            CommentaryDelta::class => 'onTextDelta',
            MessageStart::class => 'onMessageStart',
            MessageComplete::class => 'onMessageComplete',
        ];
    }

    public function onStreamStart(RuntimeStreamLifecycleEvent $event): void
    {
        $this->onStreamTerminal($event);

        $this->emit(
            $event->runId, $event->stepId,
            RuntimeEventTypeEnum::AssistantMessageStarted,
            [],
        );
    }

    public function onMessageStart(RuntimeStreamDeltaEvent $event): void
    {
        $state = &$this->state($event->runId, $event->stepId);
        $delta = $event->delta;
        \assert($delta instanceof MessageStart);
        $item = $delta->getItem();
        $id = AssistantMessageContentMapper::blockId($event->runId, $event->stepId, $item, $delta->getOutputIndex());
        $state['activeMessage'] = [
            'block_id' => $id,
            'item_id' => $item['id'] ?? null,
            'output_index' => $delta->getOutputIndex(),
            'phase' => $item['phase'] ?? null,
        ];
        $state['messages'][$id] = $state['activeMessage'] + ['started' => false];
        $event->handled = true;
    }

    public function onMessageComplete(RuntimeStreamDeltaEvent $event): void
    {
        $state = &$this->state($event->runId, $event->stepId);
        $delta = $event->delta;
        \assert($delta instanceof MessageComplete);
        $item = MessageItem::fromText($delta->getContent());
        \assert(null !== $item);
        $id = AssistantMessageContentMapper::blockId($event->runId, $event->stepId, $item, $delta->getOutputIndex());
        $previous = $state['messages'][$id] ?? [];
        $started = (bool) ($previous['started'] ?? false);
        $payload = [
            'block_id' => $id,
            'item_id' => $item['id'] ?? null,
            'output_index' => $delta->getOutputIndex(),
            'phase' => $item['phase'] ?? $previous['phase'] ?? null,
            'text' => $delta->getContent()->getText(),
        ];
        if (!$started && '' !== $payload['text']) {
            $this->emit($event->runId, $event->stepId, RuntimeEventTypeEnum::AssistantTextStarted, $payload);
        }
        if ($started || '' !== $payload['text']) {
            $this->emit($event->runId, $event->stepId, RuntimeEventTypeEnum::AssistantTextCompleted, $payload);
        }
        $state['messages'][$id] = $payload + ['started' => true];
        if (($state['activeMessage']['block_id'] ?? null) === $id) {
            $state['activeMessage'] = null;
        }
        $event->handled = true;
    }

    public function onTextDelta(RuntimeStreamDeltaEvent $event): void
    {
        $state = &$this->state($event->runId, $event->stepId);
        if ($event->handled) {
            return;
        }

        $delta = $event->delta;
        \assert($delta instanceof TextDelta || $delta instanceof CommentaryDelta);
        $text = $delta instanceof TextDelta ? $delta->getText() : $delta->getCommentary();

        if (null !== $state['activeMessage'] && '' === $text) {
            $event->handled = true;

            return;
        }
        if (null !== $state['activeMessage']) {
            $id = (string) $state['activeMessage']['block_id'];
            $started = (bool) $state['messages'][$id]['started'];
            $state['messages'][$id]['started'] = true;
            $this->emit(
                $event->runId, $event->stepId,
                $started ? RuntimeEventTypeEnum::AssistantTextDelta : RuntimeEventTypeEnum::AssistantTextStarted,
                $state['activeMessage'] + ['text' => $text],
            );
            $event->handled = true;

            return;
        }

        if (!$state['textStarted']) {
            $state['textStarted'] = true;
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

    public function onStreamTerminal(RuntimeStreamLifecycleEvent $event): void
    {
        unset($this->streams[$this->streamKey($event->runId, $event->stepId)]);
    }

    /** @return array{textStarted: bool, activeMessage: array<string, mixed>|null, messages: array<string, array<string, mixed>>} */
    private function &state(string $runId, ?string $stepId): array
    {
        $key = $this->streamKey($runId, $stepId);
        // Retries reset only their run/step. Terminal lifecycle events release
        // the complete attempt state without disturbing overlapping streams.
        $this->streams[$key] ??= ['textStarted' => false, 'activeMessage' => null, 'messages' => []];

        return $this->streams[$key];
    }

    private function streamKey(string $runId, ?string $stepId): string
    {
        return json_encode([$runId, $stepId], \JSON_THROW_ON_ERROR);
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

        if ($this->stdoutSink instanceof StdoutRuntimeEventSink && $this->stdoutSink->isPipe()) {
            $this->stdoutSink->emit($event);

            return;
        }

        $this->sink->emit($event);
    }

    private function blockId(string $runId, ?string $stepId, string $kind): string
    {
        if (null !== $stepId) {
            return \sprintf('%s_%s_%s', $runId, $stepId, $kind);
        }

        return \sprintf('%s_%s', $runId, $kind);
    }
}
