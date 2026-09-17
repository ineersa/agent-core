<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;

/**
 * Builds the Codex Responses API JSON body shared by SSE and WebSocket transports.
 */
final class CodexRequestBodyFactory
{
    /** Internal invocation option; never sent as a top-level API field. */
    public const string REASONING_UPDATE = 'codex_reasoning_update';
    public const string REASONING_RESET = 'codex_reasoning_reset';

    public function __construct(
        private readonly ?CodexReasoningTransitionLedger $transitionLedger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function build(Model $model, array $payload, array $options): array
    {
        // Structured output: map Symfony AI RESPONSE_FORMAT into Codex text.format
        // (format lives under options['text']).
        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        // Merge order: options, then model name, then contract payload last so
        // CodexContract keys (input, instructions, …) win over duplicate top-level options.
        // Payload also wins over the injected model key when both set a field.
        $jsonBody = array_merge($options, ['model' => $model->getName()], $payload);

        $effort = $jsonBody[self::REASONING_UPDATE] ?? null;
        $reset = \array_key_exists(self::REASONING_RESET, $jsonBody);
        unset($jsonBody[self::REASONING_UPDATE], $jsonBody[self::REASONING_RESET]);

        $promptCacheKey = $this->resolvePromptCacheKey($jsonBody, $options);
        if ($reset && null !== $this->transitionLedger && null !== $promptCacheKey) {
            $this->transitionLedger->forget($promptCacheKey);
        }

        if ('gpt-6-astra' === $model->getName()) {
            $input = $jsonBody['input'] ?? [];
            if (!\is_array($input)) {
                $input = [];
            }
            /** @var list<array<string, mixed>> $cleanInput */
            $cleanInput = array_values(array_filter(
                $input,
                static fn (mixed $item): bool => \is_array($item) && 'configuration_update' !== ($item['type'] ?? null),
            ));

            $transitions = [];
            if (null !== $this->transitionLedger && null !== $promptCacheKey) {
                $transitions = $this->transitionLedger->transitions($promptCacheKey);
            }

            if (\is_string($effort) && '' !== $effort) {
                $after = $this->insertionOffset($cleanInput);
                if ($after < \count($cleanInput)) {
                    $transitions = $this->withTransition($transitions, $after, $effort);
                    if (null !== $this->transitionLedger && null !== $promptCacheKey) {
                        $this->transitionLedger->remember($promptCacheKey, $after, $effort);
                    }
                }
            }

            $jsonBody['input'] = $this->applyTransitions($cleanInput, $transitions);
        }

        // Empty prompt_cache_key in the payload must not erase a resolved options value.
        if (\array_key_exists('prompt_cache_key', $jsonBody)
            && (!\is_string($jsonBody['prompt_cache_key']) || '' === $jsonBody['prompt_cache_key'])) {
            unset($jsonBody['prompt_cache_key']);
        }
        if (!isset($jsonBody['prompt_cache_key'])
            && isset($options['prompt_cache_key'])
            && \is_string($options['prompt_cache_key'])
            && '' !== $options['prompt_cache_key']) {
            $jsonBody['prompt_cache_key'] = $options['prompt_cache_key'];
        }

        // Codex Responses defaults — pi-mono openai-codex-responses.ts buildRequestBody parity.
        $jsonBody['store'] ??= false;
        $jsonBody['stream'] ??= true;

        if (!isset($jsonBody['text'])) {
            $jsonBody['text'] = ['verbosity' => 'low'];
        } elseif (!isset($jsonBody['text']['verbosity'])) {
            $jsonBody['text']['verbosity'] = 'low';
        }

        $jsonBody['include'] ??= ['reasoning.encrypted_content'];
        $jsonBody['tool_choice'] ??= 'auto';
        $jsonBody['parallel_tool_calls'] ??= true;

        return $jsonBody;
    }

    /**
     * @param array<string, mixed> $jsonBody
     * @param array<string, mixed> $options
     */
    private function resolvePromptCacheKey(array $jsonBody, array $options): ?string
    {
        foreach ([$jsonBody['prompt_cache_key'] ?? null, $options['prompt_cache_key'] ?? null] as $candidate) {
            if (\is_string($candidate) && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $input
     */
    private function insertionOffset(array $input): int
    {
        $offset = \count($input);
        while ($offset > 0 && ('user' === ($input[$offset - 1]['role'] ?? null)
            || 'function_call_output' === ($input[$offset - 1]['type'] ?? null))) {
            --$offset;
        }

        return $offset;
    }

    /**
     * @param list<array{after: int, effort: string}> $transitions
     *
     * @return list<array{after: int, effort: string}>
     */
    private function withTransition(array $transitions, int $after, string $effort): array
    {
        foreach ($transitions as $transition) {
            if ($transition['after'] === $after && $transition['effort'] === $effort) {
                return $transitions;
            }
        }

        $transitions[] = ['after' => $after, 'effort' => $effort];

        return $transitions;
    }

    /**
     * @param list<array<string, mixed>>              $input
     * @param list<array{after: int, effort: string}> $transitions
     *
     * @return list<array<string, mixed>>
     */
    private function applyTransitions(array $input, array $transitions): array
    {
        if ([] === $transitions) {
            return $input;
        }

        usort(
            $transitions,
            static fn (array $left, array $right): int => $left['after'] <=> $right['after'],
        );

        $result = [];
        $cursor = 0;
        foreach ($transitions as $transition) {
            $after = max(0, min($transition['after'], \count($input)));
            while ($cursor < $after) {
                $result[] = $input[$cursor];
                ++$cursor;
            }
            $result[] = ['type' => 'configuration_update', 'reasoning' => ['effort' => $transition['effort']]];
        }
        while ($cursor < \count($input)) {
            $result[] = $input[$cursor];
            ++$cursor;
        }

        return $result;
    }
}
