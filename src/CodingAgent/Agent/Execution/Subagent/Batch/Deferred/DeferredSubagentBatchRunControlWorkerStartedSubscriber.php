<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * On run_control worker start, reconcile unfinished deferred batch rows for this controller session only.
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final readonly class DeferredSubagentBatchRunControlWorkerStartedSubscriber
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private MessageBusInterface $commandBus,
        #[Autowire('%env(HATFIELD_SESSION_ID)%')]
        private string $sessionId,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function __invoke(WorkerStartedEvent $event): void
    {
        if (!\in_array('run_control', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }

        $sessionId = trim($this->sessionId);
        if ('' === $sessionId || 'unknown' === $sessionId) {
            return;
        }

        foreach ($this->batchRepository->findUnfinishedByParentRunId($sessionId) as $batch) {
            try {
                $this->commandBus->dispatch(new RecoverDeferredSubagentBatchLifecycleMessage($batch->lifecycleId));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to enqueue deferred subagent batch lifecycle recovery on worker start.', previous: $exception);
            }

            if (null !== $batch->interruptionKind) {
                try {
                    $this->commandBus->dispatch(new InterruptDeferredSubagentBatchMessage(
                        $batch->lifecycleId,
                        $batch->interruptionKind,
                    ));
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to enqueue deferred subagent batch interruption on worker start.', previous: $exception);
                }

                continue;
            }

            if (null === $batch->deadlineAt) {
                continue;
            }

            if ('pending' !== $this->deferredToolCompletionRepository->status($batch->lifecycleId)) {
                continue;
            }

            $delayMs = max(0, ($batch->deadlineAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000);
            $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

            try {
                $this->commandBus->dispatch(
                    new InterruptDeferredSubagentBatchMessage(
                        $batch->lifecycleId,
                        DeferredSubagentInterruptionKindEnum::Timeout,
                    ),
                    $stamps,
                );
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to reschedule deferred subagent batch timeout on worker start.', previous: $exception);
            }
        }
    }
}
