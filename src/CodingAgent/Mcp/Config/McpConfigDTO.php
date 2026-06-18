<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

/**
 * Typed root model for .hatfield/mcp.json configuration.
 *
 * Constructed via {@see McpConfigLoader} after merge, validation, and interpolation.
 * Immutable value object.
 */
final readonly class McpConfigDTO
{
    /**
     * @param array<string, McpServerDefinitionDTO> $servers Enabled servers keyed by server name (all servers post-merge)
     */
    public function __construct(
        public array $servers = [],
    ) {
    }

    /**
     * Build from a flat map of server definitions.
     *
     * @param array<string, McpServerDefinitionDTO> $servers
     */
    public static function fromServers(array $servers): self
    {
        return new self(servers: $servers);
    }
}
