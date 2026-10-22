<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\RunPresentationProjectorFactory;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class HistoryEventStoreFactory
{
    public static function presentationFactory(): RunPresentationProjectorFactory
    {
        return new RunPresentationProjectorFactory(new ReplayAssistantMessageFactory(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()));
    }

    /** @param list<RunEvent> $events */
    public static function create(HatfieldSessionStore $sessionStore, array $events = []): SessionRunEventStore
    {
        $store = new SessionRunEventStore($sessionStore, new EventPayloadNormalizer(), new LockFactory(new FlockStore()), new NullLogger(), new FileRunSequenceAllocator(), presentations: self::presentationFactory());
        if ([] !== $events) {
            PreparedEventStoreSeeder::appendMany($store, $events);
        }

        return $store;
    }
}
