<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForksConfigDTO;

final class ForkRuntimeConfigResolver
{
    public function __construct(
        private readonly ForksConfigDTO $forksConfig,
    ) {
    }

    public function resolve(
        ?string $explicitModel,
        ?string $explicitThinking,
        ?string $parentModel,
        ?string $parentReasoning,
    ): ForkRuntimeResolvedConfigDTO {
        $model = $this->firstNonEmpty($explicitModel, $this->forksConfig->model, $parentModel);
        if (null === $model) {
            throw new \RuntimeException('Cannot launch fork: missing explicit model, forks.model, and parent execution model.');
        }

        $thinking = $this->firstNonEmpty(
            $explicitThinking,
            $this->forksConfig->thinkingLevel,
            $parentReasoning,
        );
        if (null === $thinking) {
            throw new \RuntimeException('Cannot launch fork: missing explicit thinking, forks.thinking_level, and parent reasoning.');
        }

        return new ForkRuntimeResolvedConfigDTO(model: $model, thinking: $thinking);
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (null === $candidate) {
                continue;
            }
            $trimmed = trim($candidate);
            if ('' !== $trimmed) {
                return $trimmed;
            }
        }

        return null;
    }
}
