<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate;

/**
 * Non-fatal diagnostic produced during prompt-template loading.
 *
 * Diagnostics represent local degradation such as:
 *  - name collisions (two files mapping to the same lowercase name)
 *  - unreadable files
 *  - invalid YAML frontmatter
 *  - missing explicit paths
 *
 * No raw template content is carried in diagnostics.
 *
 * @internal
 */
final readonly class PromptTemplateDiagnostic
{
    public function __construct(
        /** Diagnostic type: collision|read_error|yaml_error|invalid_path */
        public string $type,
        /** Human-readable message describing the diagnostic. */
        public string $message,
        /** Path of the file or directory involved, if applicable. */
        public string $path = '',
        /** Lowercase template name involved, for collision diagnostics. */
        public string $name = '',
        /** Winner path, for collision diagnostics. */
        public string $winnerPath = '',
        /** Loser path, for collision diagnostics. */
        public string $loserPath = '',
    ) {
    }
}
