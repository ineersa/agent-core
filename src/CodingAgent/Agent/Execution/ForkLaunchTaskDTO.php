<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

final readonly class ForkLaunchTaskDTO
{
    public function __construct(
        public string $task,
        public ?string $modelOverride = null,
        public ?string $reasoningOverride = null,
    ) {
    }
}
