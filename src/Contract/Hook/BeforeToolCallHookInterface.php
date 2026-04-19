<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallResult;

/**
 * Defines the contract for intercepting tool invocations before execution begins. It allows external systems to inspect the tool call context and optionally abort the operation via a cancellation token. This interface serves as a pre-execution hook within the agent core workflow.
 */
interface BeforeToolCallHookInterface
{
    /**
     * Intercepts tool call execution to allow pre-validation or cancellation.
     */
    public function beforeToolCall(BeforeToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?BeforeToolCallResult;
}
