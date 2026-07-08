<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Config\ModelResolver;

/**
 * Resolves effective fork runtime configuration from Hatfield settings.
 *
 * When forks.model or forks.thinking_level are unset, null means session fallbacks at execution time.
 */
final class ForkConfigResolver
{
    public function __construct(
        private readonly ForksConfigDTO $forksConfig,
    ) {
    }

    public function resolve(): ForkResolvedConfigDTO
    {
        $model = $this->forksConfig->model;
        if (null !== $model && '' === trim($model)) {
            $model = null;
        }

        $thinkingLevel = $this->forksConfig->thinkingLevel;
        if (null !== $thinkingLevel && '' === trim($thinkingLevel)) {
            $thinkingLevel = null;
        }
        if (null !== $thinkingLevel && !\in_array($thinkingLevel, ModelResolver::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid forks.thinking_level "%s". Valid levels: %s.', $thinkingLevel, implode(', ', ModelResolver::LEVELS)));
        }

        return new ForkResolvedConfigDTO(
            resolvedModel: $model,
            resolvedThinkingLevel: $thinkingLevel,
        );
    }
}
