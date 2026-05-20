<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Implements the AgentCore stream observer boundary by dispatching
 * Symfony events for each stream lifecycle callback and delta.
 *
 * Subscribers register for specific delta class FQCN strings (e.g.,
 * TextDelta::class) via EventSubscriberInterface::getSubscribedEvents().
 * Unknown delta types are silently ignored because no subscriber matches.
 *
 * The onStreamStart/onStreamEnd/onStreamError callbacks dispatch under
 * fixed event names (llm_stream.start / llm_stream.end / llm_stream.error)
 * so stream-lifecycle-aware subscribers can reset per-stream state.
 */
final class LlmStreamDispatchObserver implements LlmStreamObserverInterface
{
    public const string EVENT_START = 'llm_stream.start';
    public const string EVENT_END = 'llm_stream.end';
    public const string EVENT_ERROR = 'llm_stream.error';

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function onStreamStart(string $runId, ?string $stepId): void
    {
        $this->dispatcher->dispatch(
            new RuntimeStreamLifecycleEvent($runId, $stepId),
            self::EVENT_START,
        );
    }

    public function onDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
    {
        // Dispatch with the concrete delta class as the event name.
        // Subscribers register for exact delta FQCN so no instanceof
        // routing is needed in this class.
        $this->dispatcher->dispatch(
            new RuntimeStreamDeltaEvent($runId, $stepId, $delta),
            $delta::class,
        );
    }

    public function onStreamEnd(string $runId, ?string $stepId): void
    {
        $this->dispatcher->dispatch(
            new RuntimeStreamLifecycleEvent($runId, $stepId),
            self::EVENT_END,
        );
    }

    public function onStreamError(string $runId, ?string $stepId, \Throwable $error): void
    {
        $this->dispatcher->dispatch(
            new RuntimeStreamLifecycleEvent($runId, $stepId, $error),
            self::EVENT_ERROR,
        );
    }
}
