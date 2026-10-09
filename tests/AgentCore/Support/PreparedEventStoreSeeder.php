<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Test-only seeding through the supported prepared-transition API.
 * Finalizes each write with the verified transition identity so later
 * production commits do not see leftover intent.
 */
final class PreparedEventStoreSeeder
{
    public static function append(PreparedTransitionEventStoreInterface $store, RunEvent $event): RunEvent
    {
        return self::appendMany($store, [$event])[0];
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public static function appendMany(PreparedTransitionEventStoreInterface $store, array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $runId = $events[0]->runId;
        foreach ($events as $event) {
            if ($event->runId !== $runId) {
                throw new \InvalidArgumentException('PreparedEventStoreSeeder requires one run identity.');
            }
        }

        $persisted = $store->appendTransition($events, [
            'run_id' => $runId,
            'predecessor_seq' => $store->latestSequenceFor($runId) ?? 0,
            'effects' => [],
            'actions' => [],
            'post_commit_effects' => [],
            'after_turn_hooks' => false,
        ]);
        $pending = $store->verifiedPendingTransition($runId);
        if (null === $pending) {
            throw new \RuntimeException('PreparedEventStoreSeeder requires a verified pending transition.');
        }
        $store->finalizeVerifiedTransition($runId, $pending->identity);

        return $persisted;
    }
}
