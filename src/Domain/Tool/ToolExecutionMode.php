<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Defines the execution modes for tools within the agent core domain. This enum provides a type-safe way to distinguish between different operational contexts for tool invocation. It serves as a value object to enforce strict typing for execution behavior.
 */
enum ToolExecutionMode: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Interrupt = 'interrupt';
}
