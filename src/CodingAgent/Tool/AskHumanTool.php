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
 * - Generates stable fallback `question_id` from prompt/kind/choices/metadata hash.
 * - Normalizes bare string choices to structured `{label, description}` objects.
 * - Preserves UI metadata: header, ui_kind/kind, choices, default.
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
            description: 'Ask the user for input, confirmation, a choice, or approval when you need their response before continuing.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'The question or prompt to display to the user. Use a clear, concise question. This field is preferred over \'prompt\'.',
                    ],
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Deprecated alias for \'question\'. Prefer the \'question\' field instead.',
                    ],
                    'ui_kind' => [
                        'type' => 'string',
                        'enum' => ['text', 'confirm', 'choice', 'approval'],
                        'description' => 'Alias for kind. Overrides derivation from kind/choices if present.',
                    ],
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['text', 'confirm', 'choice', 'approval'],
                        'description' => 'The kind of question. "text" for free-form input, "confirm"/"approval" for yes/no (boolean), "choice" for selecting from options.',
                    ],
                    'choices' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                        'description' => 'List of answer choices as simple strings. Required when kind is "choice". The system derives the answer schema from kind and choices.',
                    ],
                    'default' => [
                        'description' => 'Default answer value. The v1 UI does not auto-select it; included for reference.',
                    ],
                    'question_id' => [
                        'type' => 'string',
                        'description' => 'Optional stable identifier for this question. Generated from content if absent.',
                    ],
                    'header' => [
                        'type' => 'string',
                        'description' => 'Optional header text shown above the question in the UI.',
                    ],
                ],
                'required' => ['question'],
                'additionalProperties' => false,
            ],
            handler: $this,
            promptLine: 'ask_human question [kind] [choices] — ask the user for input, confirmation, a choice, or approval',
            promptGuidelines: [
                'Use ask_human when you need the user to provide information, confirm an action, or make a choice before proceeding.',
                'Provide a clear question in the "question" field. Set "kind" to "confirm"/"approval" for yes-no, "choice" with "choices" for a selection, or "text" for free-form input.',
                'For choices, provide "choices" as an array of simple strings. The system derives the answer schema from kind and choices.',
                'Optionally provide a "default" value and "header" for UI display.',
                'If the user cancels the question, the answer will be the string \'Cancelled by user\'. Treat this as an abort signal — do not retry the same question immediately.',
                'Use ask_human only when you need the user\'s answer before you can continue.',
            ],
            executionMode: ToolExecutionMode::Interrupt,
        );
    }
}
