<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolResultText;

final class MalformedToolResultException extends ToolCallException
{
    public function __construct()
    {
        parent::__construct(ToolResultText::FAILURE_MESSAGE, retryable: false);
    }
}
