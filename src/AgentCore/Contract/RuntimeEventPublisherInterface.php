<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Contract for publishing transient runtime events to live consumers.
 *
 * This is the AgentCore-side boundary — implementations live in
 * CodingAgent and handle transport-specific delivery (e.g., Messenger
 * publish bus). Use native types only to avoid coupling to protocol DTOs.
 *
 * See also: RuntimeEventSinkInterface for in-process/sync event emission.
 *
 * Methods carry individual RuntimeEvent fields so the contract stays
 * portable and does not require AgentCore to depend on protocol types.
 */
interface RuntimeEventPublisherInterface
{
    /**
     * Publish a transient runtime event for live consumers.
     *
     * @param string               $runId   active run identifier
     * @param string               $type    event type string (RuntimeEventTypeEnum value)
     * @param int                  $seq     sequence number (0 for transient streaming deltas)
     * @param array<string, mixed> $payload event payload
     */
    public function publish(string $runId, string $type, int $seq, array $payload = []): void;
}
