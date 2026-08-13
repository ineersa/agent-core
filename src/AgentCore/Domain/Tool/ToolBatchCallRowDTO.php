<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fixed call_data row under a tool-batch snapshot batch_state blob.
 *
 * Wire keys remain camelCase to match historical JSON rows. Dynamic maps stay
 * keyed by tool call id outside this DTO; nested tool args/schema stay arrays.
 */
final readonly class ToolBatchCallRowDTO
{
    /**
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $assistantMessage
     * @param array<string, mixed>|null $argSchema
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public string $toolCallId,
        #[Assert\Type('string')]
        public string $toolName,
        #[Assert\Type('integer')]
        public int $orderIndex,
        #[Assert\Type('array')]
        public array $args = [],
        #[Assert\Type('string')]
        public ?string $mode = null,
        #[Assert\Type('integer')]
        public ?int $timeoutSeconds = null,
        #[Assert\Type('integer')]
        public ?int $maxParallelism = null,
        #[Assert\Type('string')]
        public ?string $toolsRef = null,
        #[Assert\Type('string')]
        public ?string $toolIdempotencyKey = null,
        #[Assert\Type('array')]
        public ?array $assistantMessage = null,
        #[Assert\Type('array')]
        public ?array $argSchema = null,
        #[Assert\Valid]
        public ?ToolCallHumanInputAnswerDTO $humanInputAnswer = null,
        #[Assert\Type('string')]
        public ?string $parentModel = null,
    ) {
    }
}
