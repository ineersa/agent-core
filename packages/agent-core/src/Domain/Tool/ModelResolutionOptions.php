<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ModelResolutionOptions
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public array $values = [],
    ) {
    }
}
