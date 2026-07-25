<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

/**
 * Worker-local handler for an asynchronous extension-agent job.
 *
 * Handlers are registered during extension register() in each process that
 * loads extensions (including messenger:consume extension_agent). They receive
 * the process-local ExtensionApiInterface so they may call agent()/sessionEvents()
 * and construct isolated tools in-process. Handlers themselves are never serialized.
 */
interface ExtensionAgentJobHandlerInterface
{
    /**
     * @param array<string, mixed> $payload JSON-safe payload from ExtensionAgentJobRequestDTO
     */
    public function handle(ExtensionApiInterface $api, array $payload, ?string $jobId, ?string $correlationId): void;
}
