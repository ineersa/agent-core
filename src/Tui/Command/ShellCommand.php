<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * A shell command, e.g. "!ls -la".
 *
 * Only single "!" is supported (EDITOR-11 MVP).
 */
final readonly class ShellCommand implements CommandParseResult
{
    /**
     * @param string $command      The shell command text (after "!")
     * @param string $originalText The full trimmed submitted text
     */
    public function __construct(
        public string $command,
        public string $originalText,
    ) {
    }

    public function originalText(): string
    {
        return $this->originalText;
    }
}
