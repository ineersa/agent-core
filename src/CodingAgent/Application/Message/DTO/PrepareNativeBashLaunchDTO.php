<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Metadata-only owner preparation of a native Bash identity before effect dispatch.
 *
 * Paths are captured before the metadata transaction so the handler never
 * touches the filesystem. Redelivery reuses the captured attempt/paths.
 */
final readonly class PrepareNativeBashLaunchDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\PositiveOrZero]
        public int $turnNo,
        #[Assert\NotBlank]
        public string $stepId,
        #[Assert\NotBlank]
        public string $toolCallId,
        #[Assert\Positive]
        public int $attempt,
        #[Assert\NotBlank]
        public string $idempotencyKey,
        #[Assert\NotBlank]
        public string $command,
        #[Assert\NotBlank]
        public string $logPath,
        #[Assert\NotBlank]
        public string $statusPath,
        public bool $backgroundRequested = false,
    ) {
    }
}
