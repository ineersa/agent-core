<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches serializable extension-agent jobs onto the dedicated transport.
 *
 * The public ExtensionApi contract promises async work on a dedicated worker.
 * sync:// would re-dispatch inline on the caller bus and can run model work
 * during AfterTurnCommit — that is fail-closed here. Allowed DSNs are process
 * async transports (doctrine://, in-memory://, etc.). Controllers override
 * HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN to Doctrine SQLite.
 */
final readonly class ExtensionAgentJobDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private string $transportDsn,
    ) {
    }

    public function dispatch(ExtensionAgentJobRequestDTO $request): void
    {
        if ($this->isSyncTransport($this->transportDsn)) {
            throw new \RuntimeException('extension_agent transport is configured as sync://; async Doctrine (or test in-memory) transport is required so extension agent jobs do not run model work on the caller thread.');
        }

        $message = new ExtensionAgentJobMessage(
            handlerId: $request->handlerId,
            payload: $request->payload,
            jobId: $request->jobId,
            correlationId: $request->correlationId,
        );

        $this->bus->dispatch($message);

        $this->logger->info('extension_agent.job.dispatched', [
            'component' => 'extension_agent',
            'event_type' => 'extension_agent.job.dispatched',
            'handler_id' => $request->handlerId,
            'job_id' => $request->jobId,
            'correlation_id' => $request->correlationId,
        ]);
    }

    private function isSyncTransport(string $dsn): bool
    {
        return str_starts_with(strtolower(trim($dsn)), 'sync://');
    }
}
