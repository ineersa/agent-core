<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/**
 * Resolved fork runtime configuration.
 *
 * Returned by {@see ForkConfigResolver::resolve()}.
 * When resolvedModel is null, ForkExecutionService falls back to the parent session model.
 * When resolvedThinkingLevel is null, ForkExecutionService falls back to the parent session reasoning.
 */
final readonly class ForkResolvedConfigDTO
{
    public function __construct(
        public ?string $resolvedModel,
        public ?string $resolvedThinkingLevel = null,
    ) {
    }
}
