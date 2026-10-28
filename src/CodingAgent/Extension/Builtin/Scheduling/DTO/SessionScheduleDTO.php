<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\Scheduling\DTO;

final readonly class SessionScheduleDTO
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $runId,
        public string $controllerRunId,
        public string $controllerId,
        public string $tool,
        public array $arguments,
        public string $every,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $expiresAt,
        public int $turnNo,
    ) {
    }
}
