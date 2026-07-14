<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * On run_control worker start, reconcile unfinished deferred single-child rows for this controller session only.
 */
#[AsEventListener(event: WorkerStartedEvent::class)]
final readonly class DeferredSingleSubagentRunControlWorkerStartedSubscriber
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
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

        foreach ($this->launchRepository->findRecoverableByParentRunId($sessionId) as $projection) {
            try {
                $this->commandBus->dispatch(new RecoverDeferredSingleSubagentLifecycleMessage($projection->lifecycleId));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to enqueue deferred single subagent lifecycle recovery on worker start.', previous: $exception);
            }

            if (null !== $projection->interruptionKind) {
                try {
                    $this->commandBus->dispatch(new InterruptDeferredSingleSubagentMessage(
                        $projection->lifecycleId,
                        $projection->interruptionKind,
                    ));
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to enqueue deferred single subagent interruption on worker start.', previous: $exception);
                }

                continue;
            }

            if (null === $projection->deadlineAt) {
                continue;
            }

            if ('pending' !== $this->deferredToolCompletionRepository->status($projection->lifecycleId)) {
                continue;
            }

            $delayMs = max(0, ($projection->deadlineAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000);
            $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

            try {
                $this->commandBus->dispatch(
                    new InterruptDeferredSingleSubagentMessage(
                        $projection->lifecycleId,
                        DeferredSubagentInterruptionKindEnum::Timeout,
                    ),
                    $stamps,
                );
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to reschedule deferred single subagent timeout on worker start.', previous: $exception);
            }
        }
    }
}
