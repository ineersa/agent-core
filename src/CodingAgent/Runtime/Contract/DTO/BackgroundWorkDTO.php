<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

use DateTimeImmutable as ImmutableDateTime;

final readonly class BackgroundWorkDTO
{
    /** @param list<string> $runIds Native owners for Bash, active children for an invocation. */
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public string $status,
        public bool $terminal,
        public array $runIds,
        public ?int $processId = null,
        public ?ImmutableDateTime $startedAt = null,
        public ?string $toolCallId = null,
    ) {
    }
}
