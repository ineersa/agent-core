<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

/**
 * Catalog entry for a single MCP server.
 *
 * Records whether discovery succeeded or failed and, when connected,
 * the list of discovered tools.
 */
final readonly class McpServerCatalogEntryDTO
{
    /**
     * @param string                     $serverName   Server name key
     * @param string                     $transport    Transport type: "stdio" | "http"
     * @param McpServerCatalogStatusEnum $status       Discovery outcome
     * @param string|null                $errorMessage Diagnostic-safe error message when failed
     * @param list<McpToolDefinitionDTO> $tools        Discovered tools (empty on failure)
     */
    public function __construct(
        public string $serverName,
        public string $transport,
        public McpServerCatalogStatusEnum $status,
        public ?string $errorMessage = null,
        public array $tools = [],
    ) {
    }

    /**
     * Serialize to the catalog JSON shape.
     *
     * @return array{serverName: string, transport: string, status: string, errorMessage: string|null, tools: list<array>}
     */
    public function toArray(): array
    {
        return [
            'serverName' => $this->serverName,
            'transport' => $this->transport,
            'status' => $this->status->value,
            'errorMessage' => $this->errorMessage,
            'tools' => array_map(static fn (McpToolDefinitionDTO $t) => $t->toArray(), $this->tools),
        ];
    }

    /**
     * Hydrate from a catalog JSON entry.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tools = [];
        if (isset($data['tools']) && \is_array($data['tools'])) {
            foreach ($data['tools'] as $toolData) {
                if (\is_array($toolData)) {
                    $tools[] = McpToolDefinitionDTO::fromArray($toolData);
                }
            }
        }

        $status = McpServerCatalogStatusEnum::tryFrom((string) ($data['status'] ?? ''))
            ?? McpServerCatalogStatusEnum::FAILED;

        return new self(
            serverName: (string) ($data['serverName'] ?? ''),
            transport: (string) ($data['transport'] ?? 'unknown'),
            status: $status,
            errorMessage: isset($data['errorMessage']) && \is_string($data['errorMessage'])
                ? $data['errorMessage']
                : null,
            tools: $tools,
        );
    }
}
