<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/**
 * OutboxSink defines the contract for persisting domain events to an outbox storage mechanism. It ensures reliable event delivery by providing a standardized interface for writing event payloads to durable storage.
 */
enum OutboxSink: string
{
    case Jsonl = 'jsonl';
    case Mercure = 'mercure';
}
