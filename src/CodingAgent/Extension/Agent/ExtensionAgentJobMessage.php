<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

/**
 * Internal Messenger envelope for asynchronous extension-agent jobs.
 *
 * Contains only JSON-safe scalars/arrays. Must not carry live tool handlers.
 */
final readonly class ExtensionAgentJobMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $handlerId,
        public array $payload = [],
        public ?string $jobId = null,
        public ?string $correlationId = null,
    ) {
    }
}
