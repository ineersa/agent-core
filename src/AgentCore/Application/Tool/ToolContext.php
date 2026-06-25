<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;

/**
 * Concrete execution context populated by ToolExecutor for each invocation.
 */
final readonly class ToolContext
{
    public function __construct(
        private string $runId,
        private int $turnNo,
        private string $toolCallId,
        private string $toolName,
        private CancellationTokenInterface $cancellationToken,
        private ?int $timeoutSeconds,
        private int $orderIndex = 0,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function turnNo(): int
    {
        return $this->turnNo;
    }

    public function toolCallId(): string
    {
        return $this->toolCallId;
    }

    public function toolName(): string
    {
        return $this->toolName;
    }

    public function cancellationToken(): CancellationTokenInterface
    {
        return $this->cancellationToken;
    }

    public function timeoutSeconds(): ?int
    {
        return $this->timeoutSeconds;
    }

    public function orderIndex(): int
    {
        return $this->orderIndex;
    }
}
