<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Typed DTO for the top-level `runtime.*` settings section.
 *
 * Owns controller-process runtime knobs that are not tool policy and not
 * agent-discovery policy. Hydrated by Symfony Serializer from merged Hatfield
 * config (defaults.yaml → home settings → project settings). Bounds are
 * declared with Symfony Validator attributes and enforced by AppConfig after
 * denormalization.
 */
final readonly class RuntimeConfigDTO
{
    public const int DEFAULT_LLM_CONCURRENCY = 10;

    public const int DEFAULT_TOOL_CONCURRENCY = 16;

    /**
     * @param int $llmConcurrency Same-process Fiber admission limit for the
     *                            single llm consumer (`hatfield:messenger:consume-llm`)
     */
    public function __construct(
        #[Assert\Positive(
            message: 'runtime.llm_concurrency must be a positive integer.',
        )]
        public int $llmConcurrency = self::DEFAULT_LLM_CONCURRENCY,
        #[Assert\Positive(
            message: 'runtime.tool_concurrency must be a positive integer.',
        )]
        public int $toolConcurrency = self::DEFAULT_TOOL_CONCURRENCY,
    ) {
    }
}
