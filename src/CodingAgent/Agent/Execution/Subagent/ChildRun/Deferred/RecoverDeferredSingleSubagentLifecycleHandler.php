<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class RecoverDeferredSingleSubagentLifecycleHandler
{
    public function __construct(
        private DeferredSingleSubagentRecoveryService $recoveryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecoverDeferredSingleSubagentLifecycleMessage $message): void
    {
        try {
            $this->recoveryService->recover($message->lifecycleId);
        } catch (\Throwable $exception) {
            $this->logger->warning('deferred_single_subagent.lifecycle_recovery_failed', [
                'lifecycle_id' => $message->lifecycleId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.lifecycle_recovery_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
