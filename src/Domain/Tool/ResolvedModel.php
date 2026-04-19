<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * A readonly value object representing a resolved AI model configuration with its associated options. It encapsulates the model identifier and any specific parameters required for instantiation. This class serves as a data carrier for model resolution within the domain layer.
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
