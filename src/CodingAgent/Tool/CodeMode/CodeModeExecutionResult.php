<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

/**
 * Script return value plus bounded stdout/stderr captured during execution.
 *
 * Nested tool() results stay plain values. Only the outer code_mode result
 * uses this envelope so ToolResult processors can expose diagnostics separately.
 *
 * @internal
 */
final readonly class CodeModeExecutionResult
{
    /**
     * @param array{stdout?: string, stderr?: string} $diagnostics
     */
    public function __construct(
        public mixed $result,
        public array $diagnostics = [],
    ) {
    }

    public function hasDiagnostics(): bool
    {
        $stdout = $this->diagnostics['stdout'] ?? '';
        $stderr = $this->diagnostics['stderr'] ?? '';

        return ('' !== $stdout) || ('' !== $stderr);
    }
}
