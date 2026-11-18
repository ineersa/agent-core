<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Structured JSON-line logger for the Claude Code MCP server and hooks.
 *
 * stdout carries the MCP protocol (and, for hooks, would reach the
 * conversation), so diagnostics go to stderr, which Claude Code keeps in its
 * MCP server and debug logs. Callers pass event names and
 * correlation fields only, never tool output or memory content. Debug records
 * (per-message SDK traces) are dropped.
 */
final class StderrLogger extends AbstractLogger
{
    /** @param resource $stream */
    public function __construct(private $stream = \STDERR)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (LogLevel::DEBUG === $level) {
            return;
        }
        $line = json_encode(['level' => $level, 'message' => (string) $message] + $context, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        fwrite($this->stream, $line."\n");
    }
}
