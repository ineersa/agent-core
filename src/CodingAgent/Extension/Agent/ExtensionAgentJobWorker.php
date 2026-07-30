<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Agent;

use Ineersa\AgentCore\Contract\Extension\ChildRunExtensionAllowlistReaderInterface;
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
 *
 * For child runs, jobs owned by extensions outside the durable allowlist are
 * dropped (no handler invocation, no side effects).
 */
final readonly class ExtensionAgentJobWorker
{
    public function __construct(
        private ExtensionAgentJobRegistry $registry,
        private ExtensionApiInterface $extensionApi,
        private LoggerInterface $logger,
        private ?ChildRunExtensionAllowlistReaderInterface $extensionAllowlistReader = null,
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

        if ($this->isBlockedForChildRun($message)) {
            $this->logger->info('extension_agent.job.skipped_child_allowlist', [
                'component' => 'extension_agent',
                'event_type' => 'extension_agent.job.skipped_child_allowlist',
                'handler_id' => $message->handlerId,
                'job_id' => $message->jobId,
                'correlation_id' => $message->correlationId,
                'owner' => $this->registry->ownerClass($message->handlerId),
            ]);

            return;
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

    private function isBlockedForChildRun(ExtensionAgentJobMessage $message): bool
    {
        if (null === $this->extensionAllowlistReader) {
            return false;
        }

        $owner = $this->registry->ownerClass($message->handlerId);
        if (null === $owner) {
            return false;
        }

        $runId = $this->resolveRunId($message);
        if (null === $runId) {
            return false;
        }

        $allowed = $this->extensionAllowlistReader->readAllowedExtensions($runId);
        if (null === $allowed) {
            // Parent / non-child run.
            return false;
        }

        return !\in_array($owner, $allowed, true);
    }

    private function resolveRunId(ExtensionAgentJobMessage $message): ?string
    {
        $fromPayload = $message->payload['run_id'] ?? null;
        if (\is_string($fromPayload) && '' !== trim($fromPayload)) {
            return trim($fromPayload);
        }

        $correlation = $message->correlationId;
        if (\is_string($correlation) && '' !== trim($correlation)) {
            return trim($correlation);
        }

        return null;
    }
}
