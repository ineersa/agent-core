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
        $currentCount = self::inputCount($currentRequestBody);

        if (!CodexWebSocketContinuationComparator::requestBodiesMatchExceptInput($currentRequestBody, $this->lastRequestBody)) {
            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_DIVERGENT_BODY,
                $keyContext,
                $keyChanged,
                $baselineCount,
                $currentCount,
            );
        }

        $currentInput = $currentRequestBody['input'] ?? [];
        if (!\is_array($currentInput)) {
            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_INVALID_INPUT,
                $keyContext,
                $keyChanged,
                $baselineCount,
                0,
            );
        }

        $currentCount = \count($currentInput);
        if ($currentCount < $baselineCount) {
            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_PREFIX_SHORTER,
                $keyContext,
                $keyChanged,
                $baselineCount,
                $currentCount,
            );
        }

        $prefix = \array_slice($currentInput, 0, $baselineCount);
        if (!CodexWebSocketContinuationComparator::responseInputsEqual($prefix, $baseline)) {
            $mismatch = CodexWebSocketContinuationComparator::describePrefixMismatch($prefix, $baseline);

            return CodexWebSocketContinuationDecision::reject(
                CodexWebSocketContinuationDecision::REASON_PREFIX_MISMATCH,
                $keyContext,
                $keyChanged,
                $baselineCount,
                $currentCount,
                $mismatch,
            );
        }

        $deltaInput = \array_slice($currentInput, $baselineCount);

        return CodexWebSocketContinuationDecision::accept(
            [
                'previous_response_id' => $this->lastResponseId,
                'input' => $deltaInput,
            ],
            $keyContext,
            $keyChanged,
            $baselineCount,
            $currentCount,
            \count($deltaInput),
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
