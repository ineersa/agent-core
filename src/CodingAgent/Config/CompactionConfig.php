<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Compaction settings resolved from Hatfield config.
 *
 * Immutable value object. Controls compaction behaviour: whether it is
 * enabled, the token budget for the retained tail and model response
 * reservation, an optional summary token cap, and an optional model
 * override for the summarization call.
 *
 * Hydrated from the compaction section of Hatfield merged config via
 * Symfony Serializer denormalization.
 *
 * @see SessionCompactor
 */
final readonly class CompactionConfig
{
    public const bool DEFAULT_ENABLED = true;
    public const int DEFAULT_RESERVE_TOKENS = 16384;
    public const int DEFAULT_KEEP_RECENT_TOKENS = 20000;

    public function __construct(
        public bool $enabled = self::DEFAULT_ENABLED,

        #[SerializedName('reserve_tokens')]
        public int $reserveTokens = self::DEFAULT_RESERVE_TOKENS,

        #[SerializedName('keep_recent_tokens')]
        public int $keepRecentTokens = self::DEFAULT_KEEP_RECENT_TOKENS,

        #[SerializedName('max_summary_tokens')]
        public ?int $maxSummaryTokens = null,

        /**
         * Summarization model override.
         *
         * When null, the active session model is used for summarization.
         * When non-null, the value must be a `provider/model` string
         * (e.g. `llama_cpp/flash`).
         */
        public ?string $model = null,
    ) {
    }

    /**
     * Effective maximum summary tokens.
     *
     * When maxSummaryTokens is explicitly set, that value is used.
     * When null, falls back to floor(reserveTokens * 0.8), ensuring
     * the summary leaves room for the reserve budget.
     */
    public function effectiveMaxSummaryTokens(): int
    {
        if (null !== $this->maxSummaryTokens) {
            return $this->maxSummaryTokens;
        }

        return (int) floor($this->reserveTokens * 0.8);
    }

    /**
     * Parse the model override string into a provider/model reference.
     *
     * Returns null when model is null (session model fallback).
     * Throws \InvalidArgumentException when the string is not a valid
     * provider/model reference.
     */
    public function resolveModelReference(): ?AiModelReference
    {
        if (null === $this->model) {
            return null;
        }

        return AiModelReference::parse($this->model);
    }

    /**
     * DI factory — extract compaction settings from AppConfig entity.
     *
     * Used by the Symfony container via services.yaml factory definition
     * so that autowired consumers receive the same instance that lives
     * inside AppConfig.
     */
    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->compaction;
    }
}
