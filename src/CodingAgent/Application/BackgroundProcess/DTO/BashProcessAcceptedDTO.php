<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** One-way background acceptance for run_control persistence. No reply. */
final readonly class BashProcessAcceptedDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        public string $statusPath,
        public \DateTimeImmutable $acceptedAt,
    ) {
    }
}
