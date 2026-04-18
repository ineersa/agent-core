<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ProviderRequest
{
    /**
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
