<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fixed terminal result_data row under a tool-batch snapshot batch_state blob.
 *
 * Wire keys remain camelCase. Arbitrary tool result payloads stay mixed/array —
 * only the stable envelope fields are typed. pendingHumanInput is bus-only and
 * must never appear here.
 */
final readonly class ToolBatchResultRowDTO
{
    /**
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public string $toolCallId,
        #[Assert\Type('integer')]
        public int $orderIndex,
        public mixed $result = null,
        #[Assert\Type('bool')]
        public bool $isError = false,
        #[Assert\Type('array')]
        public ?array $error = null,
    ) {
    }
}
