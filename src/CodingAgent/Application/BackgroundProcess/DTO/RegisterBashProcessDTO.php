<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterBashProcessDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-f0-9]{32}$/D')]
        public string $identity,
        #[Assert\NotBlank]
        #[Assert\Regex('/^[1-9][0-9]*$/D')]
        public string $ownerSessionId,
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\Positive]
        public int $pid,
        #[Assert\Positive]
        public ?int $pgid,
        public string $command,
        public ?string $toolCallId,
        public ?int $deadlineNanoseconds,
        public bool $backgroundRequested,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        public string $logPath,
        #[Assert\NotBlank]
        public string $statusPath,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
