<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsEventListener(event: DeferredToolCompletionRegisteredEvent::class)]
final readonly class DeferredToolCompletionRegisteredSubagentListener
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private MessageBusInterface $commandBus,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function __invoke(DeferredToolCompletionRegisteredEvent $event): void
    {
        $correlation = $event->correlation;
        $projection = $this->launchRepository->findByParentRunAndToolCall($correlation->runId, $correlation->toolCallId);
        if (null === $projection) {
            return;
        }

        if ($projection->lifecycleId !== $correlation->deferredId) {
            return;
        }

        $projection = $this->launchRepository->findByLifecycleId($projection->lifecycleId) ?? $projection;

        try {
            $this->commandBus->dispatch(new DeliverDeferredSingleSubagentLifecycleMessage($projection->lifecycleId));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to enqueue deferred single subagent lifecycle delivery.', previous: $exception);
        }

        if (null !== $projection->interruptionKind) {
            try {
                $this->commandBus->dispatch(new InterruptDeferredSingleSubagentMessage(
                    $projection->lifecycleId,
                    $projection->interruptionKind,
                ));
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to enqueue deferred single subagent interruption after registration.', previous: $exception);
            }

            return;
        }

        if (null === $projection->deadlineAt) {
            return;
        }

        $delayMs = max(0, ($projection->deadlineAt->getTimestamp() - $this->clock->now()->getTimestamp()) * 1000);
        $stamps = $delayMs > 0 ? [new DelayStamp($delayMs)] : [];

        try {
            $this->commandBus->dispatch(
                new InterruptDeferredSingleSubagentMessage(
                    $projection->lifecycleId,
                    DeferredSingleSubagentInterruptionKindEnum::Timeout,
                ),
                $stamps,
            );
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to schedule deferred single subagent timeout interruption.', previous: $exception);
        }
    }
}
