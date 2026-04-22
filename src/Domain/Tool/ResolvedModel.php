<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Resolved AI model identifier with its associated configuration options for provider instantiation.
 */
final readonly class ResolvedModel
{
    /**
     * Initializes the resolved model with its identifier and optional configuration parameters.
     *
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $model,
        public array $options = [],
    ) {
    }
}
