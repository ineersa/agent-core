<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Pure canonical event-stream preparation for replay orchestration.
 */
final class ReplayEventPreparer
{
    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function sortBySequence(array $events): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    /**
     * @param list<RunEvent> $events sorted ascending by seq
     *
     * @return list<int>
     */
    public function duplicateSequences(array $events): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($events as $event) {
            if (isset($seen[$event->seq])) {
                $duplicates[$event->seq] = $event->seq;
            } else {
                $seen[$event->seq] = true;
            }
        }

        return array_values($duplicates);
    }

    /**
     * @param list<RunEvent> $events sorted ascending by seq
     *
     * @return list<int>
     */
    public function missingSequences(array $events): array
    {
        $missing = [];
        $expected = 1;

        foreach ($events as $event) {
            if ($event->seq < $expected) {
                continue;
            }

            while ($expected < $event->seq) {
                $missing[] = $expected;
                ++$expected;
            }

            ++$expected;
        }

        return $missing;
    }

    /**
     * @param list<RunEvent> $events
     */
    public function maxSequence(array $events): int
    {
        if ([] === $events) {
            return 0;
        }

        return (int) max(array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }
}
