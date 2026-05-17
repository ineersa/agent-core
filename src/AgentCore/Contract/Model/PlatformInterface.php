<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Model;

use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;

interface PlatformInterface
{
    /**
     * Executes a model invocation request and returns structured provider output.
     */
    public function invoke(ModelInvocationRequest $request): PlatformInvocationResult;
}
