<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class DeliverDeferredSubagentBatchLifecycleHandler
{
    public function __construct(
        private DeferredSubagentBatchLifecycleDeliveryService $deliveryService,
    ) {
    }

    public function __invoke(DeliverDeferredSubagentBatchLifecycleMessage $message): void
    {
        $this->deliveryService->deliver($message->batchLifecycleId);
    }
}
