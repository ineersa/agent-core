<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

/**
 * Type of question/input expected from the user.
 *
 * Mirrors common interaction patterns: free-text input, binary
 * confirmation, and structured choice. The kind determines which
 * TUI widget and input mode are used for rendering.
 *
 * Schema-driven: the extension's schema (supplied via
 * ToolCallDecisionDTO::requireApproval()) determines the kind:
 * - boolean schema (type=boolean) -> Confirm
 * - enum schema (type=string, enum=[...]) -> Choice
 * - else -> Text
 *
 * The TUI contains ZERO extension-specific knowledge. Adding a new
 * approval-granting extension requires only implementing the
 * ExtensionApi contracts — TUI rendering is determined generically
 * from the schema.
 */
enum QuestionKind: string
{
    case Text = 'text';
    case Confirm = 'confirm';
    case Choice = 'choice';
}
