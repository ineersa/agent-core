<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface;
use Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RetireUnknownExecutionHandler
{
    public function __construct(private ExecutionOperationStoreInterface $operations, private ToolExecutionAuthorizationInterface $tools, private PreparedTransitionEventStoreInterface $transitions)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(RetireUnknownExecutionDTO $action): void
    {
        $verified = $this->transitions->verifiedPendingTransition($action->notice->runId());
        if (null === $verified) {
            throw new \RuntimeException('Unknown retirement requires a verified repair transition.');
        }
        ($action->notice instanceof ExecutionOutcomeUnknown ? $this->operations : $this->tools)->retireUnknownExecution($action, $verified);
    }
}
