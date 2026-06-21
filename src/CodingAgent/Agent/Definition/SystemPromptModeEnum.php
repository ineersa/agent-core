<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * How the agent's system prompt interacts with the parent's system prompt.
 *
 *  - replace: The agent's instructions replace the parent's system prompt
 *             (clean slate — default for most agents).
 *  - append:  The agent's instructions are appended to the parent's system
 *             prompt (used when the agent needs parent context).
 */
enum SystemPromptModeEnum: string
{
    case Replace = 'replace';
    case Append = 'append';
}
