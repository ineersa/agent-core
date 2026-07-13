<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: DeferredToolCompletionRegisteredEvent::class)]
final readonly class DeferredToolCompletionRegisteredSubagentListener
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private MessageBusInterface $commandBus,
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

        $this->commandBus->dispatch(new DeliverDeferredSingleSubagentLifecycleMessage($projection->lifecycleId));
    }
}
