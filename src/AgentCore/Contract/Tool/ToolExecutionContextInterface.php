<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

/**
 * Ambient execution context for a single tool invocation.
 *
 * Provides run/tool metadata and access to the run-level cancellation token
 * so tools can perform cooperative cancellation checks without importing
 * AgentCore domain/infrastructure classes.
 */
interface ToolExecutionContextInterface
{
    public function runId(): string;

    public function turnNo(): int;

    public function toolCallId(): string;

    public function toolName(): string;

    public function cancellationToken(): CancellationTokenInterface;

    public function timeoutSeconds(): int;
}
