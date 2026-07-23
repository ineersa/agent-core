<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Generic worker for extension-agent jobs.
 *
 * Resolves a process-local registered handler by stable ID and invokes it with
 * the process-local ExtensionApi. Unknown handlers fail permanently so retries
 * cannot loop forever when registration is missing.
 */
final readonly class ExtensionAgentJobWorker
{
    public function __construct(
        private ExtensionAgentJobRegistry $registry,
        private ExtensionApiInterface $extensionApi,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExtensionAgentJobMessage $message): void
    {
        $handler = $this->registry->get($message->handlerId);
        if (null === $handler) {
            $this->logger->error('extension_agent.job.unknown_handler', [
                'component' => 'extension_agent',
                'event_type' => 'extension_agent.job.unknown_handler',
                'handler_id' => $message->handlerId,
                'job_id' => $message->jobId,
                'correlation_id' => $message->correlationId,
            ]);

            throw new UnrecoverableMessageHandlingException(\sprintf('No extension agent job handler registered for "%s".', $message->handlerId));
        }

        $this->logger->info('extension_agent.job.started', [
            'component' => 'extension_agent',
            'event_type' => 'extension_agent.job.started',
            'handler_id' => $message->handlerId,
            'job_id' => $message->jobId,
            'correlation_id' => $message->correlationId,
        ]);

        try {
            $handler->handle(
                $this->extensionApi,
                $message->payload,
                $message->jobId,
                $message->correlationId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('extension_agent.job.failed', [
                'component' => 'extension_agent',
                'event_type' => 'extension_agent.job.failed',
                'handler_id' => $message->handlerId,
                'job_id' => $message->jobId,
                'correlation_id' => $message->correlationId,
                'exception_class' => $e::class,
            ]);
            throw $e;
        }

        $this->logger->info('extension_agent.job.completed', [
            'component' => 'extension_agent',
            'event_type' => 'extension_agent.job.completed',
            'handler_id' => $message->handlerId,
            'job_id' => $message->jobId,
            'correlation_id' => $message->correlationId,
        ]);
    }
}
