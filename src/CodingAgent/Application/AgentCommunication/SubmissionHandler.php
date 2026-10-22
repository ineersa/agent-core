<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\CodingAgent\Application\AgentCommunication\DTO\AcceptSubmissionDTO;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class SubmissionHandler
{
    public function __construct(private RunLockManager $locks, private OwnerRunInitializationMiddleware $initialization, private RunMessageProcessor $processor)
    {
    }

    public function __invoke(AcceptSubmissionDTO $message): void
    {
        $this->locks->synchronized($message->runId(), function () use ($message): void {
            $this->initialization->initializeForOwner($message->runId(), $message);
            $this->processor->process('agent_submission', $message);
        });
    }
}
