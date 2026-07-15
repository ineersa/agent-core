<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class InterruptDeferredSubagentBatchHandler
{
    public function __construct(
        private DeferredSubagentBatchInterruptionService $interruptionService,
    ) {
    }

    public function __invoke(InterruptDeferredSubagentBatchMessage $message): void
    {
        $this->interruptionService->interrupt($message->batchLifecycleId, $message->kind);
    }
}
