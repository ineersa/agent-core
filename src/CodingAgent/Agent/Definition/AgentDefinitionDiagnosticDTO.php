<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Non-fatal diagnostic produced during agent-definition discovery.
 *
 * Diagnostics represent local degradation such as:
 *  - name collisions (two definitions mapping to the same agent name)
 *  - invalid definition files (parse/validation errors)
 *  - missing explicit configured paths
 *
 * No raw file content is carried in diagnostics.
 *
 * @internal
 */
final readonly class AgentDefinitionDiagnosticDTO
{
    public function __construct(
        /** Diagnostic type: collision|invalid_definition|missing_path */
        public string $type,
        /** Human-readable message describing the diagnostic. */
        public string $message,
        /** Path of the file or directory involved, if applicable. */
        public string $path = '',
        /** Agent name involved, for collision diagnostics. */
        public string $name = '',
        /** Winner path, for collision diagnostics. */
        public string $winnerPath = '',
        /** Loser path, for collision diagnostics. */
        public string $loserPath = '',
    ) {
    }
}
