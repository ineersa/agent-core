<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

final readonly class BashBackgroundCandidateDTO
{
    public function __construct(
        public int $id,
        public string $toolCallId,
        public int $pid,
    ) {
    }
}
