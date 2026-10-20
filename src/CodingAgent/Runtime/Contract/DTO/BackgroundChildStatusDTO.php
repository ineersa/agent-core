<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

final readonly class BackgroundChildStatusDTO
{
    public function __construct(
        public string $lifecycleId,
        public string $runId,
        public string $artifactId,
        public string $name,
        public string $task,
        public string $model,
        public string $reasoning,
        public string $status,
        public bool $backgrounded,
    ) {
    }
}
