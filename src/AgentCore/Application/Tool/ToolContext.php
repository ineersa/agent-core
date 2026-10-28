<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Tool;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;

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
        private ?ToolCallHumanInputAnswerDTO $humanInputAnswer = null,
        private ?string $stepId = null,
        private ?string $parentModel = null,
        private ?ToolLaunchContextDTO $launchContext = null,
        private bool $allowsHumanInput = true,
        private bool $retainOutput = false,
    ) {
    }

    public function allowsHumanInput(): bool
    {
        return $this->allowsHumanInput;
    }

    public function retainOutput(): bool
    {
        return $this->retainOutput;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function turnNo(): int
    {
        return $this->turnNo;
    }

    public function stepId(): ?string
    {
        return $this->stepId;
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

    /**
     * Typed human-input answer for resumed tool-call continuation (internal only).
     */
    public function humanInputAnswer(): ?ToolCallHumanInputAnswerDTO
    {
        return $this->humanInputAnswer;
    }

    /**
     * Canonical parent execution model snapshot that produced this tool call.
     * Child launches must inherit this value rather than re-resolving session/default.
     */
    public function parentModel(): ?string
    {
        return $this->parentModel;
    }

    /**
     * Owner-prepared immutable launch input for fork/subagent tools.
     * Null for ordinary tools so their execution context stays lean.
     */
    public function launchContext(): ?ToolLaunchContextDTO
    {
        return $this->launchContext;
    }
}
