<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Explicit repair from retained terminal evidence, never fresh execution. */
final readonly class RedriveDeferredToolCall extends AbstractAgentBusMessage
{
    public function __construct(string $runId, public string $deferredId)
    {
        parent::__construct($runId, 0, $deferredId, 1, $deferredId);
    }
}
