<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/**
 * CodingAgent chooses background-completion delivery for acknowledged deferred work.
 * AgentCore only freezes the first accepted terminal result and settles after send.
 */
interface DeferredAcknowledgedCompletionRouterInterface
{
    public function deliver(string $deferredId, ToolCallResult $result): void;
}
