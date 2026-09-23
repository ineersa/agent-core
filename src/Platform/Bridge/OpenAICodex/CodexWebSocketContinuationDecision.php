<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Privacy-safe continuation outcome for structured WebSocket logs.
 *
 * Never carries raw prompt_cache_key, prompts, provider output, or tool args.
 */
final readonly class CodexWebSocketContinuationDecision
{
    public const string REASON_DELTA = 'delta';
    public const string REASON_DIVERGENT_BODY = 'divergent_body';
    public const string REASON_PREFIX_SHORTER = 'prefix_shorter';
    public const string REASON_PREFIX_MISMATCH = 'prefix_mismatch';
    public const string REASON_INVALID_INPUT = 'invalid_input';

    /**
     * @param array{previous_response_id: string, input: list<array<string, mixed>>}|null $delta
     */
    public function __construct(
        public string $reason,
        public ?array $delta,
        public bool $promptCacheKeyPresent,
        public ?string $promptCacheKeyFp,
        public int $promptCacheKeyLength,
        public bool $promptCacheKeyChanged,
        public int $baselineInputCount,
        public int $currentInputCount,
        public ?int $deltaInputCount,
        public ?int $firstMismatchIndex,
        public ?string $leftItemKind,
        public ?string $rightItemKind,
        public bool $prefixNormalizedEqual,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'reason' => $this->reason,
            'prompt_cache_key_present' => $this->promptCacheKeyPresent,
            'prompt_cache_key_fp' => $this->promptCacheKeyFp,
            'prompt_cache_key_length' => $this->promptCacheKeyLength,
            'prompt_cache_key_changed' => $this->promptCacheKeyChanged,
            'baseline_input_count' => $this->baselineInputCount,
            'current_input_count' => $this->currentInputCount,
            'delta_input_count' => $this->deltaInputCount,
            'first_mismatch_index' => $this->firstMismatchIndex,
            'left_item_kind' => $this->leftItemKind,
            'right_item_kind' => $this->rightItemKind,
            'prefix_normalized_equal' => $this->prefixNormalizedEqual,
        ];
    }

    /**
     * Bounded fingerprint of the wire prompt_cache_key only (never content).
     *
     * @param array<string, mixed> $body
     *
     * @return array{prompt_cache_key_present: bool, prompt_cache_key_fp: ?string, prompt_cache_key_length: int}
     */
    public static function promptCacheKeyContext(array $body): array
    {
        $key = $body['prompt_cache_key'] ?? null;
        if (!\is_string($key) || '' === $key) {
            return [
                'prompt_cache_key_present' => false,
                'prompt_cache_key_fp' => null,
                'prompt_cache_key_length' => 0,
            ];
        }

        return [
            'prompt_cache_key_present' => true,
            // HMAC of the key with itself, truncated — same family idea as cache_family_fp.
            'prompt_cache_key_fp' => substr(hash_hmac('sha256', $key, $key), 0, 16),
            'prompt_cache_key_length' => \strlen($key),
        ];
    }

    /**
     * @param array<string, mixed> $currentBody
     * @param array<string, mixed> $lastBody
     */
    public static function promptCacheKeyChanged(array $currentBody, array $lastBody): bool
    {
        $current = $currentBody['prompt_cache_key'] ?? null;
        $last = $lastBody['prompt_cache_key'] ?? null;
        $currentKey = \is_string($current) && '' !== $current ? $current : null;
        $lastKey = \is_string($last) && '' !== $last ? $last : null;

        return $currentKey !== $lastKey;
    }
}
