<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallResult;

/**
 * Defines the contract for processing events after a tool execution completes within the agent core. It enables decoupled post-processing logic by providing context and cancellation support for the result.
 */
interface AfterToolCallHookInterface
{
    /**
     * Processes the result of a tool call using the provided context and optional cancellation token.
     */
    public function afterToolCall(AfterToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?AfterToolCallResult;
}
