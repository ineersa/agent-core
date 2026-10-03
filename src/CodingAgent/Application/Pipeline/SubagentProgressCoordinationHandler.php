<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Application\Message\ConsumeSubagentProgressDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SubagentProgressCoordinationHandler
{
    public function __construct(private DeferredSubagentBatchRepository $batchRepository, private MessageBusInterface $commandBus)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(ConsumeSubagentProgressDTO $action): void
    {
        $current = $this->batchRepository->findByLifecycleId($action->lifecycleId)
            ?? throw new \RuntimeException('Subagent progress lifecycle disappeared during consumption.');
        if ($action->forced) {
            $this->batchRepository->markInterruptionProgressEnqueued($current->lifecycleId, $action->consumedAt, $current->projectionVersion);
        } else {
            // Superseded progress retires the current obligation, not an append receipt.
            $revision = $action->discarded ? $current->aggregateProgressRevision : $action->revision;
            $this->batchRepository->markDeliveredProgressRevision($current->lifecycleId, $revision, $current->projectionVersion);
        }
        $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($action->lifecycleId));
    }
}
