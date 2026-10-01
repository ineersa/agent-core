<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Tests\Session\History\InMemoryHistoryProjectionStore;

final class RunStartedMetadataReaderTestFactory
{
    public static function fromEventStore(EventStoreInterface $eventStore, string $runId): RunStartedMetadataReader
    {
        $history = new InMemoryHistoryProjectionStore();
        $event = null;
        foreach ($eventStore->rangeFor($runId, 1, \PHP_INT_MAX) as $candidate) {
            $event = $candidate;
            break;
        }
        $history->initializeFromEvents($runId, null === $event ? [] : [$event]);

        return new RunStartedMetadataReader($history);
    }

    public static function empty(): RunStartedMetadataReader
    {
        return new RunStartedMetadataReader(new InMemoryHistoryProjectionStore());
    }
}
