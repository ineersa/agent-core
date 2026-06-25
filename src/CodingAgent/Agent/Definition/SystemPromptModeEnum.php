<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * How optional append content is merged into the child system prompt.
 *
 *  - replace: Child prompt uses instructions + child harness only (default).
 *             Does not include APPEND_SYSTEM.md or prompt contributors.
 *  - append:  Also includes rendered APPEND_SYSTEM.md and extension prompt
 *             contributors using child-safe placeholders (not the parent
 *             system prompt).
 */
enum SystemPromptModeEnum: string
{
    case Replace = 'replace';
    case Append = 'append';
}
