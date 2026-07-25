<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;
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
final readonly class RuntimeConfig
{
    public const int DEFAULT_LLM_WORKER_COUNT = 4;

    public const int MIN_LLM_WORKER_COUNT = 1;

    public const int MAX_LLM_WORKER_COUNT = 8;

    /**
     * @param int $llmWorkerCount Fixed messenger:consume llm worker pool size
     *                            launched by HeadlessController at startup
     */
    public function __construct(
        #[SerializedName('llm_worker_count')]
        #[Assert\Range(
            min: self::MIN_LLM_WORKER_COUNT,
            max: self::MAX_LLM_WORKER_COUNT,
            notInRangeMessage: 'runtime.llm_worker_count must be an integer between {{ min }} and {{ max }}.',
        )]
        public int $llmWorkerCount = self::DEFAULT_LLM_WORKER_COUNT,
    ) {
    }

    /**
     * DI factory — extract runtime settings from AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->runtime;
    }
}
