<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Discriminated result of parsing submitted editor text.
 *
 * Each variant (NormalPrompt, SlashCommand, ShellCommand) represents
 * a different kind of input the user submitted.
 * Use instanceof checks or match() to switch on the result type.
 */
interface CommandParseResult
{
    /** The original submitted text after trimming. */
    public function originalText(): string;
}
