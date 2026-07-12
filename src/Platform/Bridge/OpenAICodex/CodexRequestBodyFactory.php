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
     * @var list<string>
     */
    private const array INTERNAL_OPTION_KEYS = [
        '_agent_core_invocation',
        '_hatfield_reasoning',
        'tool_stream',
        'tools_ref',
        'turn_no',
        'run_id',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function build(Model $model, array $payload, array $options): array
    {
        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema']['schema'])) {
            $schema = $options[PlatformSubscriber::RESPONSE_FORMAT]['json_schema'];
            $options['text']['format'] = $schema;
            $options['text']['format']['name'] = $schema['name'];
            $options['text']['format']['type'] = $options[PlatformSubscriber::RESPONSE_FORMAT]['type'];

            unset($options[PlatformSubscriber::RESPONSE_FORMAT]);
        }

        $bodyOptions = array_diff_key($options, array_flip(self::INTERNAL_OPTION_KEYS));
        $runId = $options['run_id'] ?? null;

        $jsonBody = array_merge($bodyOptions, ['model' => $model->getName()], $payload);

        if (\is_string($runId) && '' !== $runId) {
            $jsonBody['prompt_cache_key'] ??= $runId;
        }

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
