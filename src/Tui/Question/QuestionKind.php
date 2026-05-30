<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * Type of question/input expected from the user.
 *
 * Mirrors common interaction patterns: free-text input, binary
 * confirmation, structured choice, and approval requests. The kind
 * determines which TUI widget and input mode are used for rendering.
 */
enum QuestionKind: string
{
    case Text = 'text';
    case Confirm = 'confirm';
    case Choice = 'choice';
    case Approval = 'approval';
}
