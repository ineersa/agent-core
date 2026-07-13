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
    /**
     * Internal option keys that must not be serialized into the Codex JSON body.
     *
     * Injected by Hatfield/Symfony AI for routing, metadata, or hook dispatch — not
     * valid Codex Responses API fields. build() strips these before merging.
     *
     * stream is deliberately omitted: Codex requires stream=true and LlmPlatformAdapter
     * may inject it via options; it must survive sanitization.
     *
     * @var list<string>
     */
    private const array INTERNAL_OPTION_KEYS = [
        '_agent_core_invocation',
        '_hatfield_reasoning',
        'tool_stream',
        'tools_ref',
        'turn_no',
        'run_id',
        'provider_cache_key',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function build(Model $model, array $payload, array $options): array
    {
        // Structured output: map Symfony AI RESPONSE_FORMAT into Codex text.format
        // before internal keys are stripped (format lives under options['text']).
        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        $bodyOptions = array_diff_key($options, array_flip(self::INTERNAL_OPTION_KEYS));

        // provider_cache_key (persisted session) or run_id (child run) drives prompt_cache_key internally.
        $cacheSource = $options['provider_cache_key'] ?? null;
        if (!\is_string($cacheSource) || '' === $cacheSource) {
            $cacheSource = $options['run_id'] ?? null;
        }

        // Merge order: bodyOptions, then model name, then contract payload last so
        // CodexContract keys (input, instructions, …) win over duplicate top-level options.
        // Payload also wins over the injected model key when both set a field.
        $jsonBody = array_merge($bodyOptions, ['model' => $model->getName()], $payload);

        // After merge: non-empty explicit prompt_cache_key in payload wins; empty string is treated as absent.
        if (\array_key_exists('prompt_cache_key', $payload)) {
            $cacheKeyInPayload = $payload['prompt_cache_key'];
            if (\is_string($cacheKeyInPayload) && '' === $cacheKeyInPayload) {
                unset($jsonBody['prompt_cache_key']);
                if (\is_string($cacheSource) && '' !== $cacheSource) {
                    $jsonBody['prompt_cache_key'] = $cacheSource;
                }
            }
        } elseif (\is_string($cacheSource) && '' !== $cacheSource) {
            $jsonBody['prompt_cache_key'] ??= $cacheSource;
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
