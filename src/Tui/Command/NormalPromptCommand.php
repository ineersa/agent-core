<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Marker for submitted text that is a regular prompt — not a slash or shell command.
 *
 * Callers keep the original submitted text themselves; this type only
 * discriminates parse results.
 */
final readonly class NormalPromptCommand implements CommandParseResult
{
}
