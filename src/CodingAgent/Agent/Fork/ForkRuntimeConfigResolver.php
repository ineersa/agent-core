<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Config\ModelResolver;

final class ForkRuntimeConfigResolver
{
    public function __construct(
        private readonly ForksConfigDTO $forksConfig,
        private readonly ModelResolver $modelResolver,
    ) {
    }

    public function resolve(
        ?string $explicitModel,
        ?string $explicitThinking,
        ?string $parentModel,
        ?string $parentReasoning,
        string $parentRunId = '',
    ): ForkRuntimeResolvedConfigDTO {
        $model = $this->firstNonEmpty($explicitModel, $this->forksConfig->model, $parentModel);
        if (null === $model) {
            throw new \RuntimeException('Cannot launch fork: missing explicit model, forks.model, and parent execution model.');
        }

        // explicit → forks.thinking_level → parent run_started reasoning →
        // canonical ModelResolver (session / ai.default_reasoning / product medium).
        $thinking = $this->firstNonEmpty(
            $explicitThinking,
            $this->forksConfig->thinkingLevel,
            $parentReasoning,
        );
        if (null === $thinking) {
            $thinking = trim($this->modelResolver->resolveInitialReasoning(null, $parentRunId));
        }
        if ('' === $thinking) {
            throw new \RuntimeException('Cannot launch fork: canonical reasoning resolution produced an empty value.');
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
