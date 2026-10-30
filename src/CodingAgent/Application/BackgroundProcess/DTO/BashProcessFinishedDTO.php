<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Symfony\Component\Validator\Constraints as Assert;

/** One-way native completion facts for run_control persistence. No reply. */
final readonly class BashProcessFinishedDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        public string $statusPath,
        public ?int $exitCode,
        public BackgroundProcessStatusEnum $status,
        public bool $stoppedByUser,
        public \DateTimeImmutable $finishedAt,
    ) {
    }
}
