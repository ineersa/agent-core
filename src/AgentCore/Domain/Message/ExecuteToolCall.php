<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

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
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $toolCallId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $toolName,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public array $args,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public int $orderIndex,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?string $toolIdempotencyKey = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?string $mode = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?int $timeoutSeconds = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?int $maxParallelism = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?array $assistantMessage = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?array $argSchema = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?string $toolsRef = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        #[Assert\Valid]
        public ?ToolCallHumanInputAnswerDTO $humanInputAnswer = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?string $parentModel = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public bool $backgroundPromptAllowed = true,
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
            parentModel: $this->parentModel,
            backgroundPromptAllowed: $this->backgroundPromptAllowed,
        );
    }
}
