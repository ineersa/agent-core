<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess\DTO;

final readonly class ForegroundBashProcessDTO
{
    public function __construct(
        public int $id,
        public ?int $pid,
        public string $toolCallId,
    ) {
    }
}
