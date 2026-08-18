<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

/**
 * Plain TUI view of one configured MCP server for /mcp rendering.
 *
 * Keeps MCP catalog DTOs behind CompactHeader so TuiListener stays
 * Deptrac-clean.
 */
final readonly class McpServerStatusView
{
    /**
     * @param list<string> $toolNames Hatfield tool names when connected
     */
    public function __construct(
        public string $name,
        public string $transport,
        public string $status,
        public ?string $errorMessage = null,
        public array $toolNames = [],
    ) {
    }
}
