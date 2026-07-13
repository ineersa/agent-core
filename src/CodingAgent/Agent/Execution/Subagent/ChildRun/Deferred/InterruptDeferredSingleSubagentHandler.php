<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class InterruptDeferredSingleSubagentHandler
{
    public function __construct(
        private DeferredSingleSubagentInterruptionService $interruptionService,
    ) {
    }

    public function __invoke(InterruptDeferredSingleSubagentMessage $message): void
    {
        $this->interruptionService->interrupt($message->lifecycleId, $message->kind);
    }
}
