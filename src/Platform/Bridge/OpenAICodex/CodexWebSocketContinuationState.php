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
        if (!CodexWebSocketContinuationComparator::requestBodiesMatchExceptInput($currentRequestBody, $this->lastRequestBody)) {
            return null;
        }

        $currentInput = $currentRequestBody['input'] ?? [];
        if (!\is_array($currentInput)) {
            return null;
        }

        $update = null;
        if ('gpt-6-astra' === ($currentRequestBody['model'] ?? null)) {
            foreach ($currentInput as $item) {
                if ('configuration_update' === ($item['type'] ?? null)) {
                    $update = $item;
                }
            }
            $currentInput = self::withoutConfigurationUpdates($currentInput);
        }

        /** @var list<array<string, mixed>> $baseline */
        $baseline = array_merge(
            $this->lastRequestBody['input'] ?? [],
            $this->lastResponseItems,
        );
        if ('gpt-6-astra' === ($currentRequestBody['model'] ?? null)) {
            $baseline = self::withoutConfigurationUpdates($baseline);
        }

        if (\count($currentInput) < \count($baseline)) {
            return null;
        }

        $prefix = \array_slice($currentInput, 0, \count($baseline));
        if (!CodexWebSocketContinuationComparator::responseInputsEqual($prefix, $baseline)) {
            return null;
        }

        $delta = \array_slice($currentInput, \count($baseline));
        if ([] !== $delta && null !== $update) {
            array_unshift($delta, $update);
        }

        return [
            'previous_response_id' => $this->lastResponseId,
            'input' => $delta,
        ];
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
     * Updates are wire-only controls, absent from reconstructed chat history.
     *
     * @param list<array<string, mixed>> $input
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutConfigurationUpdates(array $input): array
    {
        return array_values(array_filter($input, static fn (array $item): bool => 'configuration_update' !== ($item['type'] ?? null)));
    }
}
