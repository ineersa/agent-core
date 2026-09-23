<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

/**
 * Committed continuation baseline for a single cached WebSocket connection.
 */
final class CodexWebSocketContinuationState
{
    /**
     * @param array<string, mixed>       $lastRequestBody
     * @param list<array<string, mixed>> $lastResponseItems
     */
    public function __construct(
        private array $lastRequestBody,
        private string $lastResponseId,
        private array $lastResponseItems,
    ) {
        if ('' === $this->lastResponseId) {
            throw new \InvalidArgumentException('Codex continuation response id must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $currentRequestBody
     *
     * @return array{previous_response_id: string, input: list<array<string, mixed>>}|null
     */
    public function buildDeltaRequest(array $currentRequestBody): ?array
    {
        return $this->decide($currentRequestBody)->delta;
    }

    /**
     * Classify continuation eligibility with privacy-safe structural diagnostics.
     *
     * @param array<string, mixed> $currentRequestBody
     */
    public function decide(array $currentRequestBody): CodexWebSocketContinuationDecision
    {
        $keyContext = CodexWebSocketContinuationDecision::promptCacheKeyContext($currentRequestBody);
        $keyChanged = CodexWebSocketContinuationDecision::promptCacheKeyChanged($currentRequestBody, $this->lastRequestBody);

        /** @var list<array<string, mixed>> $baseline */
        $baseline = array_merge(
            $this->lastRequestBody['input'] ?? [],
            $this->lastResponseItems,
        );
        $baselineCount = \count($baseline);

        if (!CodexWebSocketContinuationComparator::requestBodiesMatchExceptInput($currentRequestBody, $this->lastRequestBody)) {
            return new CodexWebSocketContinuationDecision(
                reason: CodexWebSocketContinuationDecision::REASON_DIVERGENT_BODY,
                delta: null,
                promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
                promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
                promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
                promptCacheKeyChanged: $keyChanged,
                baselineInputCount: $baselineCount,
                currentInputCount: self::inputCount($currentRequestBody),
                deltaInputCount: null,
                firstMismatchIndex: null,
                leftItemKind: null,
                rightItemKind: null,
                prefixNormalizedEqual: false,
            );
        }

        $currentInput = $currentRequestBody['input'] ?? [];
        if (!\is_array($currentInput)) {
            return new CodexWebSocketContinuationDecision(
                reason: CodexWebSocketContinuationDecision::REASON_INVALID_INPUT,
                delta: null,
                promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
                promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
                promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
                promptCacheKeyChanged: $keyChanged,
                baselineInputCount: $baselineCount,
                currentInputCount: 0,
                deltaInputCount: null,
                firstMismatchIndex: null,
                leftItemKind: null,
                rightItemKind: null,
                prefixNormalizedEqual: false,
            );
        }

        $currentCount = \count($currentInput);
        if ($currentCount < $baselineCount) {
            return new CodexWebSocketContinuationDecision(
                reason: CodexWebSocketContinuationDecision::REASON_PREFIX_SHORTER,
                delta: null,
                promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
                promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
                promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
                promptCacheKeyChanged: $keyChanged,
                baselineInputCount: $baselineCount,
                currentInputCount: $currentCount,
                deltaInputCount: null,
                firstMismatchIndex: null,
                leftItemKind: null,
                rightItemKind: null,
                prefixNormalizedEqual: false,
            );
        }

        $prefix = \array_slice($currentInput, 0, $baselineCount);
        if (!CodexWebSocketContinuationComparator::responseInputsEqual($prefix, $baseline)) {
            $mismatch = CodexWebSocketContinuationComparator::describePrefixMismatch($prefix, $baseline);

            return new CodexWebSocketContinuationDecision(
                reason: CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH,
                delta: null,
                promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
                promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
                promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
                promptCacheKeyChanged: $keyChanged,
                baselineInputCount: $baselineCount,
                currentInputCount: $currentCount,
                deltaInputCount: null,
                firstMismatchIndex: $mismatch['first_mismatch_index'],
                leftItemKind: $mismatch['left_item_kind'],
                rightItemKind: $mismatch['right_item_kind'],
                prefixNormalizedEqual: $mismatch['prefix_normalized_equal'],
            );
        }

        $deltaInput = \array_slice($currentInput, $baselineCount);

        return new CodexWebSocketContinuationDecision(
            reason: CodexWebSocketContinuationDecision::REASON_DELTA,
            delta: [
                'previous_response_id' => $this->lastResponseId,
                'input' => $deltaInput,
            ],
            promptCacheKeyPresent: $keyContext['prompt_cache_key_present'],
            promptCacheKeyFp: $keyContext['prompt_cache_key_fp'],
            promptCacheKeyLength: $keyContext['prompt_cache_key_length'],
            promptCacheKeyChanged: $keyChanged,
            baselineInputCount: $baselineCount,
            currentInputCount: $currentCount,
            deltaInputCount: \count($deltaInput),
            firstMismatchIndex: null,
            leftItemKind: null,
            rightItemKind: null,
            prefixNormalizedEqual: true,
        );
    }

    /**
     * @param array<string, mixed>       $fullRequestBody
     * @param list<array<string, mixed>> $responseItems
     */
    public static function fromSuccessfulResponse(
        array $fullRequestBody,
        string $responseId,
        array $responseItems,
    ): self {
        $canonicalItems = [];
        foreach ($responseItems as $item) {
            if (\is_array($item)) {
                $canonicalItems[] = $item;
            }
        }

        return new self($fullRequestBody, $responseId, $canonicalItems);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function inputCount(array $body): int
    {
        $input = $body['input'] ?? null;

        return \is_array($input) ? \count($input) : 0;
    }
}
