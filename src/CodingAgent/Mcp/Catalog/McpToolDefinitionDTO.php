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
     * @param string                    $hatfieldName Namespaced Hatfield tool identifier (e.g. "filesystem_read_file")
     * @param string                    $serverName   Originating MCP server name
     * @param string                    $mcpName      Original MCP tool name (e.g. "read_file")
     * @param string                    $description  Human-readable description (empty string when absent)
     * @param array<string, mixed>      $inputSchema  JSON Schema for the tool's arguments as a PHP array
     * @param array<string, mixed>|null $outputSchema JSON Schema for structured output, when declared
     */
    public function __construct(
        public string $hatfieldName,
        public string $serverName,
        public string $mcpName,
        public string $description,
        public array $inputSchema,
        public ?array $outputSchema = null,
    ) {
    }

    /**
     * Serialize to the catalog JSON shape.
     *
     * @return array{hatfieldName: string, serverName: string, mcpName: string, description: string, inputSchema: array<string, mixed>, outputSchema?: array<string, mixed>|\stdClass}
     */
    public function toArray(): array
    {
        $data = [
            'hatfieldName' => $this->hatfieldName,
            'serverName' => $this->serverName,
            'mcpName' => $this->mcpName,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
        if (null !== $this->outputSchema) {
            $data['outputSchema'] = [] === $this->outputSchema ? new \stdClass() : $this->outputSchema;
        }

        return $data;
    }

    /**
     * Hydrate from a catalog JSON entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $inputSchema = $data['inputSchema'] ?? null;
        $outputSchema = $data['outputSchema'] ?? null;

        return new self(
            hatfieldName: (string) ($data['hatfieldName'] ?? ''),
            serverName: (string) ($data['serverName'] ?? ''),
            mcpName: (string) ($data['mcpName'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            inputSchema: \is_array($inputSchema) ? $inputSchema : [],
            outputSchema: \is_array($outputSchema) || $outputSchema instanceof \stdClass ? (array) $outputSchema : null,
        );
    }
}
