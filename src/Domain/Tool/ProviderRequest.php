<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * A readonly value object representing a tool provider request with model, input, and options parameters. It provides a method to apply these parameters onto a specific model context, returning the resulting configuration array.
 */
final readonly class ProviderRequest
{
    /**
     * Initializes the request with optional model, input, and options parameters.
     *
     * @param array<string, mixed>|null $input
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        public ?string $model = null,
        public ?array $input = null,
        public ?array $options = null,
    ) {
    }

    /**
     * Applies the stored request parameters onto the specified model and returns the resulting array.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options
     *
     * @return array{model: string, input: array<string, mixed>, options: array<string, mixed>}
     */
    public function applyOn(string $model, array $input, array $options): array
    {
        return [
            'model' => $this->model ?? $model,
            'input' => $this->input ?? $input,
            'options' => $this->options ?? $options,
        ];
    }
}
