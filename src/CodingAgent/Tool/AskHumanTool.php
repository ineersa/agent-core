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
 * - Always generates stable output `question_id` from question/kind/choices/header hash.
 * - Normalizes bare string choices to structured `{label, description}` objects.
 * - Preserves UI metadata: header, kind, choices.
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
                        'description' => 'The clear, concise question to display to the user.',
                    ],
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['confirm'],
                        'description' => 'Optional. Set to "confirm" for yes/no or approval questions (boolean). Omit for free-form text or when providing "choices". Mutually exclusive with "choices".',
                    ],
                    'choices' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                        ],
                        'description' => 'Non-empty list of answer choices as simple strings. Providing choices selects choice mode; omit kind. Mutually exclusive with kind="confirm". Do not pass an empty list.',
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
                'If the user cancels the question, the answer will be the string \'Cancelled by user\'. Treat this as an abort signal — do not retry the same question immediately.',
            ],
            executionMode: ToolExecutionMode::Interrupt,
        );
    }
}
