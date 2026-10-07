<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Delivers captured coordination messages. Mailbox and scheduling metadata
 * apply through LocalMetadataCoordinator under the owner transaction.
 */
final readonly class CoordinationActionHandler
{
    public function __construct(private MessageBusInterface $commandBus)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function dispatchMessage(DispatchCoordinationMessageDTO $action): void
    {
        try {
            $this->commandBus->dispatch($action->message);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException($action->errorMessage, previous: $exception);
        }
    }
}
