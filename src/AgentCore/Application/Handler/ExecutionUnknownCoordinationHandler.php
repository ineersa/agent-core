<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ExecutionUnknownCoordinationHandler
{
    public function __construct(private ExecutionOperationStoreInterface $operations, private PreparedTransitionEventStoreInterface $transitions)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(ConsumeExecutionUnknownDTO $action): void
    {
        $verified = $this->transitions->verifiedPendingTransition($action->notice->runId());
        if (null === $verified) {
            throw new \RuntimeException('Unknown execution acknowledgement requires a verified transition.');
        }
        $this->operations->consumeUnknownNotice($action, $verified);
    }
}
