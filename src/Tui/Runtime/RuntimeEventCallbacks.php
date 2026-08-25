<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;

/**
 * Shared nullable-callback dispatch for runtime events, used by both
 * RuntimeEventPoller (parent run) and SubagentLiveChildViewPoller
 * (child live view).
 *
 * Dispatch order per event: human_input.requested →
 * tool_question.requested → tool terminal (completed/failed/cancelled).
 * Each callback is invoked inside its own try/catch so one bad callback
 * never drops later events in the same batch. Log identity (message,
 * component, event_type) is poller-specific so the structured logs keep
 * their exact fields per poller.
 */
final readonly class RuntimeEventCallbacks
{
    /** @var \Closure(RuntimeEvent): void|null */
    private readonly ?\Closure $onHumanInputRequested;

    /** @var \Closure(RuntimeEvent): void|null */
    private readonly ?\Closure $onToolQuestionRequested;

    /** @var \Closure(RuntimeEvent): void|null */
    private readonly ?\Closure $onToolTerminal;

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $logMessage,
        private readonly string $component,
        private readonly string $eventType,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
    ) {
        $this->onHumanInputRequested = self::toClosure($onHumanInputRequested);
        $this->onToolQuestionRequested = self::toClosure($onToolQuestionRequested);
        $this->onToolTerminal = self::toClosure($onToolTerminal);
    }

    /**
     * Dispatch an event to the matching callbacks in fixed order.
     */
    public function dispatch(RuntimeEvent $event, string $runId): void
    {
        if (null !== $this->onHumanInputRequested && RuntimeEventTypeEnum::HumanInputRequested->value === $event->type) {
            $this->invoke($this->onHumanInputRequested, $event, $runId, 'onHumanInputRequested');
        }

        if (null !== $this->onToolQuestionRequested && RuntimeEventTypeEnum::ToolQuestionRequested->value === $event->type) {
            $this->invoke($this->onToolQuestionRequested, $event, $runId, 'onToolQuestionRequested');
        }

        if (null !== $this->onToolTerminal && (
            RuntimeEventTypeEnum::ToolExecutionCompleted->value === $event->type
            || RuntimeEventTypeEnum::ToolExecutionFailed->value === $event->type
            || RuntimeEventTypeEnum::ToolExecutionCancelled->value === $event->type
        )) {
            $this->invoke($this->onToolTerminal, $event, $runId, 'onToolTerminal');
        }
    }

    /**
     * Drain {@see AgentSessionClient::events()} into a list, normalizing the
     * Traversable case shared by both pollers.
     *
     * @return list<RuntimeEvent>
     */
    public static function eventList(AgentSessionClient $client, string $runId, int $afterSeq = 0): array
    {
        $events = $client->events($runId, $afterSeq);
        if ($events instanceof \Traversable) {
            return iterator_to_array($events, false);
        }

        return $events;
    }

    /**
     * @param \Closure(RuntimeEvent): void $callback
     */
    private function invoke(\Closure $callback, RuntimeEvent $event, string $runId, string $callbackName): void
    {
        try {
            $callback($event);
        } catch (\Throwable $e) {
            $this->logger->warning($this->logMessage, [
                'component' => $this->component,
                'event_type' => $this->eventType,
                'run_id' => $runId,
                'callback' => $callbackName,
                'runtime_event_type' => $event->type,
                'seq' => $event->seq,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    private static function toClosure(?callable $callback): ?\Closure
    {
        return null !== $callback ? \Closure::fromCallable($callback) : null;
    }
}
