<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\DTO;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/** Worker-local publication handoff after ordinary terminal processing. */
final class ProcessedToolResultDTO
{
    public bool $handled = false;

    public function __construct(public readonly ToolCallResult $result, public readonly ExecuteToolCall $invocation)
    {
    }
}
