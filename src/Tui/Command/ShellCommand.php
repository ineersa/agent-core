<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * A shell command, e.g. "!ls -la" or "!!secret cmd".
 */
final readonly class ShellCommand implements CommandParseResult
{
    /**
     * @param string $command      The shell command text (after "!" or "!!")
     * @param bool   $hidden       Whether output should be hidden ("!!" = true)
     * @param string $originalText The full trimmed submitted text
     */
    public function __construct(
        public string $command,
        public bool $hidden,
        public string $originalText,
    ) {}

    public function originalText(): string
    {
        return $this->originalText;
    }
}
