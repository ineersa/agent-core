<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** Content identity and bounded routing/correlation metadata, never a filesystem path. */
final readonly class ToolPayloadReferenceDTO
{
    public function __construct(
        #[Assert\Choice(choices: ['call', 'result', 'shell', 'correlation'])]
        public string $kind,
        #[Assert\NotBlank, Assert\Length(max: 255, countUnit: Assert\Length::COUNT_BYTES), Assert\Regex(pattern: '~^[^/\\\\\x00]+$~')]
        public string $runId,
        #[Assert\PositiveOrZero]
        public int $turnNo,
        #[Assert\NotBlank, Assert\Length(max: 255, countUnit: Assert\Length::COUNT_BYTES)]
        public string $stepId,
        #[Assert\PositiveOrZero]
        public int $attempt,
        #[Assert\NotBlank, Assert\Length(max: 255, countUnit: Assert\Length::COUNT_BYTES)]
        public string $idempotencyKey,
        #[Assert\NotBlank, Assert\Length(max: 255, countUnit: Assert\Length::COUNT_BYTES)]
        public string $toolCallId,
        #[Assert\PositiveOrZero]
        public int $orderIndex,
        #[Assert\Length(max: 255, countUnit: Assert\Length::COUNT_BYTES)]
        public ?string $toolName,
        #[Assert\NotBlank, Assert\Regex('/^[a-f0-9]{64}$/')]
        public string $sha256,
        #[Assert\Positive]
        public int $bytes,
    ) {
    }
}
