<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

/**
 * A single MCP tool definition as stored in the session catalog.
 *
 * Immutable value object carrying the Hatfield-mapped name, the original
 * MCP server and tool names, the human-readable description, and the
 * JSON Schema that defines the tool's expected arguments.
 */
final readonly class McpToolDefinitionDTO
{
    /**
     * @param string               $hatfieldName Namespaced Hatfield tool identifier (e.g. "filesystem_read_file")
     * @param string               $serverName   Originating MCP server name
     * @param string               $mcpName      Original MCP tool name (e.g. "read_file")
     * @param string               $description  Human-readable description (empty string when absent)
     * @param array<string, mixed> $inputSchema  JSON Schema for the tool's arguments as a PHP array
     */
    public function __construct(
        public string $hatfieldName,
        public string $serverName,
        public string $mcpName,
        public string $description,
        public array $inputSchema,
    ) {
    }

    /**
     * Serialize to the catalog JSON shape.
     *
     * @return array{hatfieldName: string, serverName: string, mcpName: string, description: string, inputSchema: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'hatfieldName' => $this->hatfieldName,
            'serverName' => $this->serverName,
            'mcpName' => $this->mcpName,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }

    /**
     * Hydrate from a catalog JSON entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hatfieldName: (string) ($data['hatfieldName'] ?? ''),
            serverName: (string) ($data['serverName'] ?? ''),
            mcpName: (string) ($data['mcpName'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            inputSchema: isset($data['inputSchema']) && \is_array($data['inputSchema'])
                ? $data['inputSchema']
                : [],
        );
    }
}
