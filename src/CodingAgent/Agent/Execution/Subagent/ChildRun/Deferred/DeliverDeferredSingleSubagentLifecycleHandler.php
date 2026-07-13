<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class DeliverDeferredSingleSubagentLifecycleHandler
{
    public function __construct(
        private DeferredSingleSubagentLifecycleDeliveryService $deliveryService,
    ) {
    }

    public function __invoke(DeliverDeferredSingleSubagentLifecycleMessage $message): void
    {
        $this->deliveryService->deliver($message->lifecycleId);
    }
}
