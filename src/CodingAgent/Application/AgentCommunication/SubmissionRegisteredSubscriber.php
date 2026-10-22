<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SubmissionRegisteredSubscriber
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    #[AsEventListener]
    public function __invoke(DeferredToolCompletionRegisteredEvent $event): void
    {
        $source = $event->correlation;
        if (\in_array($source->toolName, ['agent_steer', 'notify_parent'], true)) {
            $this->commandBus->dispatch(new AcceptSubmissionDTO($source->runId, $source->deferredId));
        }
    }
}
