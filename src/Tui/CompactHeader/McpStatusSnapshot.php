<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

/**
 * Snapshot used by /mcp: configured servers + catalog generation markers.
 */
final readonly class McpStatusSnapshot
{
    /**
     * @param list<McpServerStatusView> $servers
     */
    public function __construct(
        public array $servers,
        public int $generation = 0,
        public string $generatedAt = '',
        public ?string $configError = null,
    ) {
    }
}
