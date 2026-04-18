<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ResolvedModel
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $model,
        public array $options = [],
    ) {
    }
}
