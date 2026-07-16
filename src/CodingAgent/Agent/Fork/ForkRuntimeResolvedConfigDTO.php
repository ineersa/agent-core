<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

final readonly class ForkRuntimeResolvedConfigDTO
{
    public function __construct(
        public ?string $model,
        public ?string $thinking,
    ) {
    }
}
