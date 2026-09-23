<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

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
        public ?string $mismatchFieldPath = null,
        public ?string $mismatchRelation = null,
        public ?string $leftValueKind = null,
        public ?string $rightValueKind = null,
    ) {
    }

    /**
     * @param array{prompt_cache_key_present: bool, prompt_cache_key_fp: ?string, prompt_cache_key_length: int} $keyContext
     * @param array{
     *     first_mismatch_index?: ?int,
     *     left_item_kind?: ?string,
     *     right_item_kind?: ?string,
     *     prefix_normalized_equal?: bool,
     *     mismatch_field_path?: ?string,
     *     mismatch_relation?: ?string,
     *     left_value_kind?: ?string,
     *     right_value_kind?: ?string
     * }|null                                                                                                                                                                  $mismatch
     */
    public static function reject(
        string $reason,
        array $keyContext,
        bool $promptCacheKeyChanged,
        int $baselineInputCount,
        int $currentInputCount,
        ?array $mismatch = null,
    ): self {
        return new self(
            reason: $reason,
            delta: null,
            promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
            promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
            promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
            promptCacheKeyChanged: $promptCacheKeyChanged,
            baselineInputCount: $baselineInputCount,
            currentInputCount: $currentInputCount,
            deltaInputCount: null,
            firstMismatchIndex: $mismatch['first_mismatch_index'] ?? null,
            leftItemKind: $mismatch['left_item_kind'] ?? null,
            rightItemKind: $mismatch['right_item_kind'] ?? null,
            prefixNormalizedEqual: $mismatch['prefix_normalized_equal'] ?? false,
            mismatchFieldPath: $mismatch['mismatch_field_path'] ?? null,
            mismatchRelation: $mismatch['mismatch_relation'] ?? null,
            leftValueKind: $mismatch['left_value_kind'] ?? null,
            rightValueKind: $mismatch['right_value_kind'] ?? null,
        );
    }

    /**
     * @param array{previous_response_id: string, input: list<array<string, mixed>>}                            $delta
     * @param array{prompt_cache_key_present: bool, prompt_cache_key_fp: ?string, prompt_cache_key_length: int} $keyContext
     */
    public static function accept(
        array $delta,
        array $keyContext,
        bool $promptCacheKeyChanged,
        int $baselineInputCount,
        int $currentInputCount,
        int $deltaInputCount,
    ): self {
        return new self(
            reason: self::REASON_DELTA,
            delta: $delta,
            promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
            promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
            promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
            promptCacheKeyChanged: $promptCacheKeyChanged,
            baselineInputCount: $baselineInputCount,
            currentInputCount: $currentInputCount,
            deltaInputCount: $deltaInputCount,
            firstMismatchIndex: null,
            leftItemKind: null,
            rightItemKind: null,
            prefixNormalizedEqual: true,
            mismatchFieldPath: null,
            mismatchRelation: null,
            leftValueKind: null,
            rightValueKind: null,
        );
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
            'mismatch_field_path' => $this->mismatchFieldPath,
            'mismatch_relation' => $this->mismatchRelation,
            'left_value_kind' => $this->leftValueKind,
            'right_value_kind' => $this->rightValueKind,
        ];
    }

    /**
     * Bounded fingerprint of a canonical UUIDv7 prompt_cache_key only.
     *
     * Non-UUIDv7 values stay marked present/length-only so low-entropy keys are
     * never self-HMAC fingerprinted.
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

        $fingerprint = null;
        if (Uuid::isValid($key) && Uuid::fromString($key) instanceof UuidV7) {
            $fingerprint = substr(hash_hmac('sha256', $key, $key), 0, 16);
        }

        return [
            'prompt_cache_key_present' => true,
            'prompt_cache_key_fp' => $fingerprint,
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
