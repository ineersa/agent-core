<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication\DTO;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;

/** Run-addressed source reply for the owner's existing effect dispatcher. */
final readonly class CompleteSubmissionDTO extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public CompleteDeferredToolCall $completion)
    {
        parent::__construct($runId, 0, $completion->deferredId, 1, $completion->deferredId);
    }
}
