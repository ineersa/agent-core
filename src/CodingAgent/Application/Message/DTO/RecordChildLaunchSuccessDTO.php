<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** One-way owner command: record child launch success after worker start/followUp. */
final readonly class RecordChildLaunchSuccessDTO
{
    public function __construct(
        #[Assert\NotBlank] public string $parentRunId,
        #[Assert\NotBlank] public string $parentToolCallId,
        #[Assert\NotBlank] public string $lifecycleId,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
