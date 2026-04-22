<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/**
 * Enumerates the supported outbox delivery targets — JSON Lines file and Mercure hub.
 */
enum OutboxSink: string
{
    case Jsonl = 'jsonl';
    case Mercure = 'mercure';
}
