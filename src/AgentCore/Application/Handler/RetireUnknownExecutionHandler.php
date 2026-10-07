<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RetireUnknownExecutionHandler
{
    public function __construct(private ExecutionOperationStoreInterface $operations, private PreparedTransitionEventStoreInterface $transitions)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(RetireUnknownExecutionDTO $action): void
    {
        $verified = $this->transitions->verifiedPendingTransition($action->notice->runId());
        if (null === $verified) {
            throw new \RuntimeException('Unknown retirement requires a verified repair transition.');
        }
        $this->operations->retireUnknownExecution($action, $verified);
    }
}
