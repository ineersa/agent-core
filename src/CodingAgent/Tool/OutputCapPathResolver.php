<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Shared path/category resolution for primary and late OutputCap stages.
 *
 * Returns either a filesystem path (for extension-based docCap selection) or a
 * synthetic document path, or null for defaultCap. Keep this list narrow:
 * document-style reads/handoffs only — never raise the global default.
 */
final class OutputCapPathResolver
{
    /**
     * Conventional tool argument keys used to determine path-specific caps.
     *
     * @var list<string>
     */
    private const array PATH_ARGUMENT_KEYS = ['path', 'file_path', 'file'];

    /**
     * Tools whose successful result is a dense document-style report/handoff.
     *
     * @var list<string>
     */
    private const array DOCUMENT_REPORT_TOOL_NAMES = ['fork', 'subagent', 'agent_retrieve'];

    /**
     * Resolve the path used for cap selection.
     *
     * Preference order:
     * 1. Explicit filesystem path-like tool argument (read/write file context),
     *    except native settings which uses dotted keys that must never be
     *    treated as filesystem paths even when they end in .md/.txt/.toon.
     * 2. Synthetic .md path for successful hatfield_docs read (not list).
     * 3. Synthetic .md path for successful document-report tools
     *    (fork/subagent/agent_retrieve) so OutputCap::capForPath applies
     *    docCap without changing defaultCap.
     * 4. null → defaultCap.
     *
     * Error results from report/docs tools keep defaultCap (null path): failed
     * envelopes are short status text, not handoff documents.
     *
     * @param array<string, mixed> $arguments
     */
    public static function resolveCapPath(
        ?string $toolName,
        array $arguments,
        bool $isError = false,
    ): ?string {
        if ('settings' === $toolName) {
            // settings.path is a dotted config key (e.g. docs.foo.md), never a file path.
            return null;
        }

        $path = self::extractPathFromArguments($arguments);
        if (null !== $path) {
            return $path;
        }

        if ($isError) {
            return null;
        }

        if ('hatfield_docs' === $toolName) {
            // Only successful document reads are doc-like; list stays defaultCap.
            return ('read' === ($arguments['operation'] ?? null))
                ? 'hatfield-docs-read.md'
                : null;
        }

        if (null !== $toolName && \in_array($toolName, self::DOCUMENT_REPORT_TOOL_NAMES, true)) {
            // Virtual doc path: only used for extension-based docCap selection.
            return 'handoff-report.md';
        }

        return null;
    }

    /**
     * Find a file-path value from tool call arguments.
     *
     * Checks known path-carrying argument keys and returns the first
     * string value found.  Returns null when no path argument exists.
     *
     * @param array<string, mixed> $arguments
     */
    public static function extractPathFromArguments(array $arguments): ?string
    {
        foreach (self::PATH_ARGUMENT_KEYS as $key) {
            $value = $arguments[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
