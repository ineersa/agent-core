<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Explicit repair of an already accepted terminal result, never fresh execution. */
final readonly class RedriveDeferredToolCall extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $deferredId)
    {
        parent::__construct($runId, 0, $deferredId, 1, $deferredId);
    }
}
