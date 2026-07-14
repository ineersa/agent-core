<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class RecoverDeferredSubagentBatchLifecycleHandler
{
    public function __construct(
        private DeferredSubagentBatchRecoveryService $recoveryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecoverDeferredSubagentBatchLifecycleMessage $message): void
    {
        try {
            $this->recoveryService->recover($message->batchLifecycleId);
        } catch (\Throwable $exception) {
            $this->logger->warning('deferred_subagent_batch.lifecycle_recovery_failed', [
                'batch_lifecycle_id' => $message->batchLifecycleId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.lifecycle_recovery_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
