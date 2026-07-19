<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * In-memory buffering sink for transient runtime events.
 *
 * Used during in-process interactive sessions. Events are buffered
 * in a queue and drained by InProcessAgentSessionClient::events()
 * on each TUI tick.
 *
 * Threading note: PHP is single-threaded, so this queue is safe
 * without explicit locking as long as emit() and drain() are called
 * within the same request/process context.
 */
final class InMemoryRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var \SplQueue<RuntimeEvent> */
    private \SplQueue $buffer;

    public function __construct()
    {
        $this->buffer = new \SplQueue();
    }

    public function emit(RuntimeEvent $event): void
    {
        $this->buffer->enqueue($event);
    }

    /**
     * Drain all buffered events for the given run.
     *
     * Draining is destructive — events are removed from the buffer.
     * This prevents duplicate yields on subsequent polls.
     *
     * @return iterable<RuntimeEvent>
     */
    public function drain(string $runId): iterable
    {
        $remaining = new \SplQueue();

        while (!$this->buffer->isEmpty()) {
            $event = $this->buffer->dequeue();

            // tool_question.requested is session-global interactive control:
            // consume once on whichever run poll drains the sink so parent/main
            // can latch child needs-input without entering the child view.
            if (
                $event->runId === $runId
                || '' === $runId
                || RuntimeEventTypeEnum::ToolQuestionRequested->value === $event->type
            ) {
                yield $event;
            } else {
                $remaining->enqueue($event);
            }
        }

        $this->buffer = $remaining;
    }
}
