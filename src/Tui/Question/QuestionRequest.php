<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * Immutable DTO representing a question/input request.
 *
 * Carries all metadata needed for rendering, routing, and persistence
 * decisions. The source field determines whether answers go to a local
 * callback (Tui) or are dispatched as runtime commands (AgentCore).
 *
 * Fields with ?string type (header, runId, questionId, toolCallId, toolName)
 * are optional metadata populated only when available from the originating
 * context. The transcript flag controls whether the question/answer pair
 * appears in session transcripts (true for AgentCore HITL, false for
 * local TUI questions).
 *
 * @param array<string, mixed> $schema  JSON Schema describing expected answer shape
 * @param list<QuestionOption> $choices Structured options for choice/approval questions
 * @param mixed                $default Default value if the user does not provide input
 */
final readonly class QuestionRequest
{
    /**
     * @param array<string, mixed> $schema  JSON Schema describing expected answer shape
     * @param list<QuestionOption> $choices Structured options for choice/approval questions
     * @param mixed                $default Default value if the user does not provide input
     */
    public function __construct(
        public string $requestId,
        public QuestionSource $source,
        public QuestionKind $kind,
        public string $prompt,
        public array $schema = ['type' => 'string'],
        public array $choices = [],
        public mixed $default = null,
        public ?string $header = null,
        public bool $allowOther = true,
        public ?string $runId = null,
        public ?string $questionId = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public bool $transcript = false,
    ) {
    }
}
