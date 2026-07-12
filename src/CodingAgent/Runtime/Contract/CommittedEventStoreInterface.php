<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\AgentCore\Contract\EventStoreInterface;

/**
 * Hatfield event store that atomically commits RunEvents and returns persisted rows.
 *
 * Standard {@see EventStoreInterface::append()} / appendMany() allocate sequence numbers
 * and return the durable events written to JSONL. Callers must not rely on draft seq values.
 */
interface CommittedEventStoreInterface extends EventStoreInterface
{
}
