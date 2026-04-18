<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

final class StreamDeltaReducer
{
    private string $text = '';
    private string $thinking = '';
    private ?string $thinkingSignature = null;

    /** @var array<string, array{id: string, name: string, arguments: array<string, mixed>, order_index: int}> */
    private array $toolCalls = [];

    /** @var array<string, string> */
    private array $toolInputBuffer = [];

    private int $toolOrderCursor = 0;

    /** @var list<array<string, mixed>> */
    private array $deltas = [];

    /** @var array<string, int|float> */
    private array $usage = [];

    public function consume(mixed $delta): void
    {
        if (\is_string($delta)) {
            $this->appendText($delta);

            return;
        }

        if (!\is_object($delta)) {
            return;
        }

        $shortClass = $this->shortClass($delta::class);

        if ('TextDelta' === $shortClass) {
            $this->appendText($this->stringFrom($delta, ['getText']));

            return;
        }

        if ('ThinkingDelta' === $shortClass) {
            $thinking = $this->stringFrom($delta, ['getThinking']);
            $this->thinking .= $thinking;
            $this->deltas[] = [
                'type' => 'thinking_delta',
                'thinking' => $thinking,
            ];

            return;
        }

        if ('ThinkingSignature' === $shortClass) {
            $signature = $this->stringFrom($delta, ['getSignature']);
            if ('' !== $signature) {
                $this->thinkingSignature = $signature;
            }

            $this->deltas[] = [
                'type' => 'thinking_signature',
                'signature' => $signature,
            ];

            return;
        }

        if ('ThinkingComplete' === $shortClass) {
            $thinking = $this->stringFrom($delta, ['getThinking']);
            if ('' !== $thinking) {
                $this->thinking = $thinking;
            }

            $signature = $this->stringFrom($delta, ['getSignature']);
            if ('' !== $signature) {
                $this->thinkingSignature = $signature;
            }

            $this->deltas[] = [
                'type' => 'thinking_complete',
                'thinking' => $thinking,
                'signature' => $signature,
            ];

            return;
        }

        if (\in_array($shortClass, ['ToolCallStart', 'ToolCallStartDelta'], true)) {
            $this->registerToolCallStart(
                id: $this->stringFrom($delta, ['getId']),
                name: $this->stringFrom($delta, ['getName']),
            );

            return;
        }

        if (\in_array($shortClass, ['ToolInputDelta', 'ToolCallDelta'], true)) {
            $this->registerToolInputDelta(
                id: $this->stringFrom($delta, ['getId']),
                name: $this->stringFrom($delta, ['getName']),
                partialJson: $this->stringFrom($delta, ['getPartialJson', 'getJson']),
            );

            return;
        }

        if (\in_array($shortClass, ['ToolCallComplete', 'ToolCallEndDelta'], true)) {
            $this->registerToolCallComplete($delta);

            return;
        }

        if ($this->looksLikeTokenUsage($delta)) {
            $this->mergeUsage($this->usageFromTokenUsage($delta));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function deltas(): array
    {
        return $this->deltas;
    }

    /**
     * @return array<string, int|float>
     */
    public function usage(): array
    {
        return $this->usage;
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantMessage(): array
    {
        $content = [];
        if ('' !== $this->text) {
            $content[] = [
                'type' => 'text',
                'text' => $this->text,
            ];
        }

        $payload = [
            'role' => 'assistant',
            'content' => $content,
        ];

        $toolCalls = $this->orderedToolCalls();
        if ([] !== $toolCalls) {
            $payload['tool_calls'] = $toolCalls;
        }

        if ('' !== $this->thinking || null !== $this->thinkingSignature) {
            $payload['details'] = array_filter([
                'thinking' => '' !== $this->thinking ? $this->thinking : null,
                'thinking_signature' => $this->thinkingSignature,
            ], static fn (mixed $value): bool => null !== $value);
        }

        return $payload;
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->toolCalls;
    }

    private function appendText(string $text): void
    {
        if ('' === $text) {
            return;
        }

        $this->text .= $text;
        $this->deltas[] = [
            'type' => 'text_delta',
            'text' => $text,
        ];
    }

    private function registerToolCallStart(string $id, string $name): void
    {
        if ('' === $id || '' === $name) {
            return;
        }

        if (!isset($this->toolCalls[$id])) {
            $this->toolCalls[$id] = [
                'id' => $id,
                'name' => $name,
                'arguments' => [],
                'order_index' => $this->toolOrderCursor++,
            ];
        }

        $this->deltas[] = [
            'type' => 'tool_call_start',
            'id' => $id,
            'name' => $name,
        ];
    }

    private function registerToolInputDelta(string $id, string $name, string $partialJson): void
    {
        if ('' === $id || '' === $name) {
            return;
        }

        if (!isset($this->toolCalls[$id])) {
            $this->registerToolCallStart($id, $name);
        }

        if ('' === $partialJson) {
            return;
        }

        $this->toolInputBuffer[$id] = ($this->toolInputBuffer[$id] ?? '').$partialJson;
        $parsedArguments = $this->parseArguments($this->toolInputBuffer[$id]);
        if (null !== $parsedArguments) {
            $this->toolCalls[$id]['arguments'] = $parsedArguments;
        }

        $this->deltas[] = [
            'type' => 'tool_input_delta',
            'id' => $id,
            'name' => $name,
            'partial_json' => $partialJson,
        ];
    }

    private function registerToolCallComplete(object $delta): void
    {
        $toolCalls = method_exists($delta, 'getToolCalls') ? $delta->getToolCalls() : null;
        if (!\is_array($toolCalls)) {
            return;
        }

        foreach ($toolCalls as $toolCall) {
            if (!\is_object($toolCall) || !method_exists($toolCall, 'getId') || !method_exists($toolCall, 'getName')) {
                continue;
            }

            $id = (string) $toolCall->getId();
            $name = (string) $toolCall->getName();

            if (!isset($this->toolCalls[$id])) {
                $this->registerToolCallStart($id, $name);
            }

            $arguments = method_exists($toolCall, 'getArguments') && \is_array($toolCall->getArguments())
                ? $toolCall->getArguments()
                : [];

            $this->toolCalls[$id]['arguments'] = $arguments;
        }

        $this->deltas[] = [
            'type' => 'tool_call_complete',
            'count' => \count($toolCalls),
        ];
    }

    /**
     * @param array<string, int|float> $usage
     */
    private function mergeUsage(array $usage): void
    {
        foreach ($usage as $name => $value) {
            $this->usage[$name] = $value;
        }
    }

    private function looksLikeTokenUsage(object $delta): bool
    {
        return method_exists($delta, 'getTotalTokens')
            || method_exists($delta, 'getPromptTokens')
            || method_exists($delta, 'getCompletionTokens');
    }

    /**
     * @return array<string, int|float>
     */
    private function usageFromTokenUsage(object $delta): array
    {
        $usage = [
            'input_tokens' => $this->numericFrom($delta, ['getPromptTokens']),
            'output_tokens' => $this->numericFrom($delta, ['getCompletionTokens']),
            'thinking_tokens' => $this->numericFrom($delta, ['getThinkingTokens']),
            'tool_tokens' => $this->numericFrom($delta, ['getToolTokens']),
            'cached_tokens' => $this->numericFrom($delta, ['getCachedTokens']),
            'cache_creation_tokens' => $this->numericFrom($delta, ['getCacheCreationTokens']),
            'cache_read_tokens' => $this->numericFrom($delta, ['getCacheReadTokens']),
            'total_tokens' => $this->numericFrom($delta, ['getTotalTokens']),
        ];

        return array_filter($usage, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedToolCalls(): array
    {
        if ([] === $this->toolCalls) {
            return [];
        }

        $toolCalls = array_values($this->toolCalls);
        usort($toolCalls, static fn (array $left, array $right): int => $left['order_index'] <=> $right['order_index']);

        return array_map(static function (array $toolCall): array {
            $encodedArguments = json_encode($toolCall['arguments']);
            if (false === $encodedArguments) {
                $encodedArguments = '{}';
            }

            return [
                'id' => $toolCall['id'],
                'name' => $toolCall['name'],
                'arguments' => $toolCall['arguments'],
                'order_index' => $toolCall['order_index'],
                'tool_idempotency_key' => hash('sha256', \sprintf('%s|%s|%s', $toolCall['id'], $toolCall['name'], $encodedArguments)),
            ];
        }, $toolCalls);
    }

    /**
     * @param array<int, string> $methods
     */
    private function stringFrom(object $value, array $methods): string
    {
        foreach ($methods as $method) {
            if (!method_exists($value, $method)) {
                continue;
            }

            $raw = $value->{$method}();
            if (\is_string($raw)) {
                return $raw;
            }

            if (\is_int($raw) || \is_float($raw)) {
                return (string) $raw;
            }

            if ($raw instanceof \Stringable) {
                return (string) $raw;
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $methods
     */
    private function numericFrom(object $value, array $methods): int|float|null
    {
        foreach ($methods as $method) {
            if (!method_exists($value, $method)) {
                continue;
            }

            $raw = $value->{$method}();
            if (\is_int($raw) || \is_float($raw)) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseArguments(string $json): ?array
    {
        if ('' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return $parts[array_key_last($parts)] ?? $fqcn;
    }
}
