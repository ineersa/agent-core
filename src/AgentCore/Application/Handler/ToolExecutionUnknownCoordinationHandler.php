<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeToolExecutionUnknownDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class ToolExecutionUnknownCoordinationHandler
{
    public function __construct(private ToolExecutionAuthorization $authorization, private PreparedTransitionEventStoreInterface $transitions)
    {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(ConsumeToolExecutionUnknownDTO $action): void
    {
        $verified = $this->transitions->verifiedPendingTransition($action->notice->runId());
        if (null === $verified) {
            throw new \RuntimeException('Unknown tool acknowledgement requires a verified transition.');
        }
        $this->authorization->consumeUnknownNotice($action, $verified);
    }
}
