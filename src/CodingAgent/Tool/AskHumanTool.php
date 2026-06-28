<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\AskHuman\AskHumanPayloadFactory;

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
 * - Uses Symfony Serializer/Validator (via AskHumanPayloadFactory) for
 *   type-safe argument denormalization, validation, and payload building.
 * - Generates stable fallback `question_id` from prompt/schema/metadata hash.
 * - Normalizes bare string choices to structured `{label, description}` objects.
 * - Preserves UI metadata: header, ui_kind/kind, choices, default, allow_other, secret.
 * - AgentCore does NOT have a defensive fallback for ask_human — it executes
 *   through the normal toolbox path where the handler runs and returns its
 *   interrupt result. AgentCore only generically preserves `kind=interrupt`
 *   payloads from any toolbox tool result.
 */
final class AskHumanTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly AskHumanPayloadFactory $payloadFactory,
    ) {
    }

    /**
     * Execute the ask_human tool.
     *
     * Returns an interrupt payload immediately. The run is paused by
     * AgentCore's existing WaitingHuman / HumanResponse flow.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     *
     * @return array<string, mixed> Interrupt payload with kind=interrupt
     *
     * @throws \Ineersa\AgentCore\Contract\Tool\ToolCallException On validation failure
     */
    public function __invoke(array $arguments): array
    {
        return $this->payloadFactory->createPayload($arguments);
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
}
