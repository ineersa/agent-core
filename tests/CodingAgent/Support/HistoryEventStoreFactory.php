<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class HistoryEventStoreFactory
{
    /** @param list<RunEvent> $events */
    public static function create(HatfieldSessionStore $sessionStore, array $events = []): SessionRunEventStore
    {
        $store = new SessionRunEventStore($sessionStore, new EventPayloadNormalizer(), new LockFactory(new FlockStore()), new NullLogger(), new FileRunSequenceAllocator());
        if ([] !== $events) {
            PreparedEventStoreSeeder::appendMany($store, $events);
        }

        return $store;
    }
}
