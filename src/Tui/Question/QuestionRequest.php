<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * Immutable DTO representing a question/input request.
 *
 * Carries the metadata needed for rendering and routing. The source field
 * determines whether answers go to a local callback (Tui) or are dispatched
 * as runtime commands (AgentCore).
 *
 * @param list<QuestionOption> $choices Structured options for choice/approval questions
 */
final readonly class QuestionRequest
{
    /**
     * @param list<QuestionOption> $choices Structured options for choice/approval questions
     */
    public function __construct(
        public string $requestId,
        public QuestionSource $source,
        public QuestionKind $kind,
        public string $prompt,
        public array $choices = [],
        public ?string $header = null,
        public bool $allowOther = true,
        public ?string $runId = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
    ) {
    }
}
