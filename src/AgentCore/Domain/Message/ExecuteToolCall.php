<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;

final readonly class ExecuteToolCall extends AbstractAgentBusMessage
{
    /**
     * Initializes the tool call execution event with run, turn, step, attempt, and idempotency context.
     *
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $assistantMessage
     * @param array<string, mixed>|null $argSchema
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolCallId,
        public string $toolName,
        public array $args,
        public int $orderIndex,
        public ?string $toolIdempotencyKey = null,
        public ?string $mode = null,
        public ?int $timeoutSeconds = null,
        public ?int $maxParallelism = null,
        public ?array $assistantMessage = null,
        public ?array $argSchema = null,
        public ?string $toolsRef = null,
        public ?ToolCallHumanInputAnswerDTO $humanInputAnswer = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }

    public function withHumanInputAnswer(?ToolCallHumanInputAnswerDTO $answer): self
    {
        return new self(
            runId: $this->runId(),
            turnNo: $this->turnNo(),
            stepId: $this->stepId(),
            attempt: $this->attempt(),
            idempotencyKey: $this->idempotencyKey(),
            toolCallId: $this->toolCallId,
            toolName: $this->toolName,
            args: $this->args,
            orderIndex: $this->orderIndex,
            toolIdempotencyKey: $this->toolIdempotencyKey,
            mode: $this->mode,
            timeoutSeconds: $this->timeoutSeconds,
            maxParallelism: $this->maxParallelism,
            assistantMessage: $this->assistantMessage,
            argSchema: $this->argSchema,
            toolsRef: $this->toolsRef,
            humanInputAnswer: $answer,
        );
    }
}
