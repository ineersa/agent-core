<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

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
    /** @var array<int, RuntimeEvent> insertion-ordered; keys are never reused */
    private array $buffer = [];

    public function emit(RuntimeEvent $event): void
    {
        $this->buffer[] = $event;
    }

    /**
     * Drain all buffered events for the given run.
     *
     * Draining is destructive — events are removed from the buffer.
     * This prevents duplicate yields on subsequent polls.
     *
     * Each event leaves the buffer only at the moment it is yielded, so a
     * consumer that stops iterating early loses nothing: other runs' events
     * and the not-yet-yielded events of this run stay buffered in order.
     *
     * Events emitted while the drain is suspended are picked up by the same
     * drain: callers yield transient events before canonical ones and rely on
     * a drain returning everything buffered so far. foreach iterates a
     * snapshot, hence the repeat until a pass finds nothing.
     *
     * @return iterable<RuntimeEvent>
     */
    public function drain(string $runId): iterable
    {
        do {
            $yielded = false;

            foreach ($this->buffer as $key => $event) {
                if ($event->runId === $runId || '' === $runId) {
                    unset($this->buffer[$key]);
                    $yielded = true;

                    yield $event;
                }
            }
        } while ($yielded);
    }
}
