<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Tool\AfterToolCallContext;
use Ineersa\AgentCore\Domain\Tool\AfterToolCallResult;

interface AfterToolCallHookInterface
{
    public function afterToolCall(AfterToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?AfterToolCallResult;
}
