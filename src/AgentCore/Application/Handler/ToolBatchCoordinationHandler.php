<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ToolBatchCoordinationHandler
{
    public function __construct(private ToolBatchCollector $collector, private PreparedTransitionEventStoreInterface $transitions)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(FinalizeToolBatchDTO $action): void
    {
        $verified = $this->transitions->verifiedPendingTransition($action->runId);
        if (null === $verified) {
            throw new \RuntimeException('Batch finalization requires a verified transition.');
        }
        $this->collector->finalizePreparedBatch($action, $verified);
    }
}
