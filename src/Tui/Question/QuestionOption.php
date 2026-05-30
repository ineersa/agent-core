<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * A single selectable option for choice-type questions.
 *
 * Mirrors the label/description pattern from Codex's
 * RequestUserInputQuestionOption for structured selection UI.
 * When description is empty, only the label is rendered.
 */
final readonly class QuestionOption
{
    public function __construct(
        public string $label,
        public string $description = '',
    ) {}
}
