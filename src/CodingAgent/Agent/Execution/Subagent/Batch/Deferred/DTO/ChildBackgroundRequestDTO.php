<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChildBackgroundRequestDTO
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $lifecycleId,
        #[Assert\NotBlank]
        public string $toolCallId,
    ) {
    }
}
