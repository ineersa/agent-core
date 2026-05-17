<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

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
