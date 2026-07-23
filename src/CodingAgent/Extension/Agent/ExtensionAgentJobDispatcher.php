<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches serializable extension-agent jobs onto the dedicated transport.
 */
final readonly class ExtensionAgentJobDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatch(ExtensionAgentJobRequestDTO $request): void
    {
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
}
