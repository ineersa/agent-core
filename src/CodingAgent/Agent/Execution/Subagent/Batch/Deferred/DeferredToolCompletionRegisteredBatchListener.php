<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: DeferredToolCompletionRegisteredEvent::class)]
final readonly class DeferredToolCompletionRegisteredBatchListener
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(DeferredToolCompletionRegisteredEvent $event): void
    {
        $correlation = $event->correlation;
        $batch = $this->batchRepository->findByParentRunAndToolCall($correlation->runId, $correlation->toolCallId);
        if (null === $batch) {
            return;
        }

        if ($batch->lifecycleId !== $correlation->deferredId) {
            return;
        }

        try {
            $this->commandBus->dispatch(new DeliverDeferredSubagentBatchLifecycleMessage($batch->lifecycleId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred subagent batch lifecycle delivery.', previous: $exception);
        }
    }
}
