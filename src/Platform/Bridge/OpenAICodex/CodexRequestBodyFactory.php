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
        unset($jsonBody[self::REASONING_UPDATE], $jsonBody[self::REASONING_RESET]);
        if ('gpt-6-astra' === $model->getName() && \is_string($effort)) {
            $input = $jsonBody['input'] ?? [];
            // Only harness-authored updates belong in outgoing input.
            $input = array_values(array_filter($input, static fn (array $item): bool => 'configuration_update' !== ($item['type'] ?? null)));
            $offset = \count($input);
            // Keep the historical prefix unchanged. Insert before new user/tool input.
            while ($offset > 0 && ('user' === ($input[$offset - 1]['role'] ?? null)
                || 'function_call_output' === ($input[$offset - 1]['type'] ?? null))) {
                --$offset;
            }
            if ($offset < \count($input)) {
                array_splice($input, $offset, 0, [['type' => 'configuration_update', 'reasoning' => ['effort' => $effort]]]);
            }
            $jsonBody['input'] = $input;
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
}
