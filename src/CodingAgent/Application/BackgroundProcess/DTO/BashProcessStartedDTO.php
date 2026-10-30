<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** One-way native start facts for run_control persistence. No reply. */
final readonly class BashProcessStartedDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\Positive]
        public int $pid,
        public ?int $pgid,
        public string $command,
        public ?string $toolCallId,
        public ?int $deadlineNanoseconds,
        public bool $backgroundRequested,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        public string $logPath,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        public string $statusPath,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
