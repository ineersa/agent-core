<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Model-visible ask_human tool — returns an interrupt payload immediately
 * so the AgentCore pipeline pauses the run and waits for human input.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * This is a thin non-blocking tool. It does NOT wait for user input;
 * AgentCore's existing WaitingHuman / HumanResponse flow owns pausing
 * and resuming the run. The TUI question overlay is managed by the
 * QuestionCoordinator and TickPollListener downstream.
 *
 * ## Key design
 *
 * - Returns `kind=interrupt` payload immediately; no oneshot/blocking path.
 * - Generates stable fallback `question_id` from prompt/schema/metadata hash.
 * - Normalizes bare string choices to structured `{label, description}` objects.
 * - Preserves UI metadata: header, ui_kind/kind, choices, default, allow_other, secret.
 * - The `__invoke` implementation returns the same interrupt-format payload
 *   so it can be used via RegistryBackedToolbox or directly.
 * - ToolExecutor has a defensive fallback for `ask_human` (and `ask_user`)
 *   that produces the same interrupt payload shape from tool call arguments.
 * - This tool definition provides the LLM-visible schema with required
 *   `question` plus optional metadata fields.
 */
final class AskHumanTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    /**
     * Execute the ask_human tool.
     *
     * Returns an interrupt payload immediately. The run is paused by
     * AgentCore's existing WaitingHuman / HumanResponse flow.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     *
     * @return array<string, mixed> Interrupt payload with kind=interrupt
     */
    public function __invoke(array $arguments): array
    {
        return self::buildInterruptPayload($arguments);
    }

    /**
     * Tool-definition metadata for automatic registry registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'ask_human',
            description: 'Ask the user for input, confirmation, a choice, or approval. The run is paused until the user responds.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'The question or prompt to display to the user. Use a clear, concise question. This field is preferred over \'prompt\'.',
                    ],
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Alias for question. Prefer the \'question\' field instead.',
                    ],
                    'ui_kind' => [
                        'type' => 'string',
                        'enum' => ['text', 'confirm', 'choice', 'approval'],
                        'description' => 'Alias for kind. Overrides derivation from schema/choices if present.',
                    ],
                    'schema' => [
                        'type' => 'object',
                        'description' => 'JSON Schema describing the expected answer format. For yes/no use {"type": "boolean"}. For a dropdown from choices use {"type": "string", "enum": ["option1", "option2"]}. Defaults to {"type": "string"}.',
                        'additionalProperties' => true,
                    ],
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['text', 'confirm', 'choice', 'approval'],
                        'description' => 'The kind of question. "text" for free-form input, "confirm"/"approval" for yes/no (boolean), "choice" for selecting from options.',
                    ],
                    'choices' => [
                        'type' => 'array',
                        'items' => [
                            'anyOf' => [
                                ['type' => 'string'],
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'label' => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                    ],
                                    'required' => ['label'],
                                ],
                            ],
                        ],
                        'description' => 'Predefined answer choices. Bare strings are accepted and normalized to {label, description} objects. Required when kind is "choice".',
                    ],
                    'default' => [
                        'description' => 'Default answer value if the user does not provide one.',
                    ],
                    'question_id' => [
                        'type' => 'string',
                        'description' => 'Optional stable identifier for this question. Generated from content if absent.',
                    ],
                    'header' => [
                        'type' => 'string',
                        'description' => 'Optional header text shown above the question in the UI.',
                    ],
                    'allow_other' => [
                        'type' => 'boolean',
                        'description' => 'Whether the user may enter a free-form answer instead of selecting from choices. Default true.',
                    ],
                    'secret' => [
                        'type' => 'boolean',
                        'description' => 'Whether the answer is sensitive (e.g. password, API key) and should be masked. Default false.',
                    ],
                ],
                'required' => ['question'],
                'additionalProperties' => false,
            ],
            handler: $this,
            promptLine: 'ask_human question [schema] [kind] [choices] — ask the user for input or confirmation; the run pauses until the user responds',
            promptGuidelines: [
                'Use ask_human when you need the user to provide information, confirm an action, or make a choice before proceeding.',
                'Provide a clear question in the "question" field. Include a JSON Schema in "schema" for structured answers.',
                'For yes/no questions, set schema to {"type": "boolean"} and kind to "confirm" or "approval".',
                'For a predefined list of options, provide choices as an array of strings or {label, description} objects, with schema {"type": "string", "enum": [...]}.',
                'Optionally provide a "default" value, "header" for UI display, and set "secret" to true for sensitive inputs.',
                'The tool returns immediately and does not block — your run is paused until the user answers, then continues automatically.',
            ],
            executionMode: ToolExecutionMode::Interrupt,
        );
    }

    /**
     * Build a normalized interrupt payload from tool call arguments.
     *
     * Note: ToolExecutor has its own parallel normalization logic in
     * interruptResult() because AgentCore must not depend on CodingAgent.
     *
     * This handler is used when AskHumanTool is invoked through
     * RegistryBackedToolbox directly; in normal runs the canonical
     * interrupt path goes through ToolExecutor::interruptResult()
     * which produces the same normalized payload shape but in-band
     * without calling the handler.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function buildInterruptPayload(array $arguments): array
    {
        $prompt = self::resolvePrompt($arguments);
        $questionId = self::resolveQuestionId($arguments, $prompt);
        $schema = self::resolveSchema($arguments);
        $choices = self::normalizeChoices($arguments);
        $kind = self::resolveKind($arguments, $schema, $choices);

        $payload = [
            'kind' => 'interrupt',
            'question_id' => $questionId,
            'prompt' => $prompt,
            'schema' => $schema,
            'ui_kind' => $kind,
        ];

        // Preserve optional UI metadata
        $header = $arguments['header'] ?? null;
        if (\is_string($header) && '' !== $header) {
            $payload['header'] = $header;
        }

        if ([] !== $choices) {
            $payload['choices'] = $choices;
        }

        if (\array_key_exists('default', $arguments)) {
            $payload['default'] = $arguments['default'];
        }

        $allowOther = $arguments['allow_other'] ?? null;
        if (\is_bool($allowOther)) {
            $payload['allow_other'] = $allowOther;
        }

        $secret = $arguments['secret'] ?? null;
        if (\is_bool($secret)) {
            $payload['secret'] = $secret;
        }

        return $payload;
    }

    /**
     * Resolve prompt text from arguments.
     *
     * @param array<string, mixed> $arguments
     */
    private static function resolvePrompt(array $arguments): string
    {
        if (isset($arguments['prompt']) && \is_string($arguments['prompt']) && '' !== $arguments['prompt']) {
            return $arguments['prompt'];
        }

        if (isset($arguments['question']) && \is_string($arguments['question']) && '' !== $arguments['question']) {
            return $arguments['question'];
        }

        return 'Please provide input.';
    }

    /**
     * Resolve a stable question_id from arguments or generate one.
     *
     * @param array<string, mixed> $arguments
     */
    private static function resolveQuestionId(array $arguments, string $prompt): string
    {
        if (isset($arguments['question_id']) && \is_string($arguments['question_id']) && '' !== $arguments['question_id']) {
            return $arguments['question_id'];
        }

        $hashInput = $prompt;

        $schema = $arguments['schema'] ?? null;
        if (\is_array($schema)) {
            $encoded = json_encode($schema, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= \is_string($encoded) ? $encoded : '';
        }

        $kind = $arguments['kind'] ?? $arguments['ui_kind'] ?? null;
        if (\is_string($kind)) {
            $hashInput .= '/kind:'.$kind;
        }

        $choices = $arguments['choices'] ?? null;
        if (\is_array($choices) && [] !== $choices) {
            $encoded = json_encode($choices, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $hashInput .= '/choices:'.(\is_string($encoded) ? $encoded : '');
        }

        $header = $arguments['header'] ?? null;
        if (\is_string($header) && '' !== $header) {
            $hashInput .= '/header:'.$header;
        }

        return 'ah_'.substr(hash('sha256', $hashInput), 0, 24);
    }

    /**
     * Resolve the schema from arguments.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function resolveSchema(array $arguments): array
    {
        if (isset($arguments['schema']) && \is_array($arguments['schema'])) {
            return $arguments['schema'];
        }

        // Derive from kind
        $kind = $arguments['kind'] ?? $arguments['ui_kind'] ?? null;

        if ('confirm' === $kind || 'approval' === $kind) {
            return ['type' => 'boolean'];
        }

        $choices = $arguments['choices'] ?? null;
        if (\is_array($choices) && [] !== $choices) {
            $enumValues = self::extractEnumValues($choices);

            return [] !== $enumValues
                ? ['type' => 'string', 'enum' => $enumValues]
                : ['type' => 'string'];
        }

        return ['type' => 'string'];
    }

    /**
     * Extract enum values from choices array.
     *
     * @param array<mixed> $choices
     *
     * @return list<string>
     */
    private static function extractEnumValues(array $choices): array
    {
        $enum = [];
        foreach ($choices as $choice) {
            if (\is_string($choice)) {
                $enum[] = $choice;
            } elseif (\is_array($choice) && isset($choice['value']) && \is_string($choice['value'])) {
                $enum[] = $choice['value'];
            } elseif (\is_array($choice) && isset($choice['label']) && \is_string($choice['label'])) {
                $enum[] = $choice['label'];
            }
        }

        return $enum;
    }

    /**
     * Resolve the ui_kind/kind from arguments or derive from schema/choices.
     *
     * @param array<string, mixed>                                             $arguments
     * @param array<string, mixed>                                             $schema
     * @param list<array{label: string, description?: string, value?: string}> $choices
     */
    private static function resolveKind(array $arguments, array $schema, array $choices): string
    {
        $explicit = $arguments['kind'] ?? $arguments['ui_kind'] ?? null;
        if (\is_string($explicit) && '' !== $explicit) {
            return $explicit;
        }

        // Derive from schema
        if (isset($schema['type']) && 'boolean' === $schema['type']) {
            return 'confirm';
        }

        if ([] !== $choices) {
            return 'choice';
        }

        return 'text';
    }

    /**
     * Normalize choices array.
     *
     * - Bare strings become {label, description} objects.
     * - Already-structured {label, value, description} objects are preserved.
     * - Every normalized choice always includes a &#039;description&#039; key (empty
     *   string when absent in input).
     * - Empty array if no choices or non-array input.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<array{label: string, description: string, value?: string}>
     */
    private static function normalizeChoices(array $arguments): array
    {
        $raw = $arguments['choices'] ?? null;
        if (!\is_array($raw) || [] === $raw) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $item) {
            if (\is_string($item)) {
                $normalized[] = ['label' => $item, 'description' => ''];
            } elseif (\is_array($item)) {
                $entry = [];
                if (isset($item['label']) && \is_string($item['label'])) {
                    $entry['label'] = $item['label'];
                } elseif (isset($item['value']) && \is_string($item['value'])) {
                    $entry['label'] = $item['value'];
                } else {
                    continue;
                }

                // Always include description (empty string when absent)
                $entry['description'] = '';
                if (isset($item['description']) && \is_string($item['description']) && '' !== $item['description']) {
                    $entry['description'] = $item['description'];
                }

                if (isset($item['value']) && \is_string($item['value'])) {
                    $entry['value'] = $item['value'];
                }

                $normalized[] = $entry;
            }
        }

        return $normalized;
    }
}
