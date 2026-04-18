<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Hook;

use Ineersa\AgentCore\Domain\Tool\BeforeToolCallContext;
use Ineersa\AgentCore\Domain\Tool\BeforeToolCallResult;

interface BeforeToolCallHookInterface
{
    public function beforeToolCall(BeforeToolCallContext $context, ?CancellationTokenInterface $cancelToken = null): ?BeforeToolCallResult;
}
