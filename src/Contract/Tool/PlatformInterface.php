<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Tool\PlatformInvocationResult;

interface PlatformInterface
{
    /**
     * Executes a model invocation request and returns structured provider output.
     */
    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult;
}
