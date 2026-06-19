<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

/**
 * Full session MCP tool catalog — the root object written to the catalog file.
 *
 * Contains metadata for invalidation and a per-server map of discovered tools.
 */
final readonly class McpToolCatalogDTO
{
    /**
     * @param int                                     $schemaVersion Schema version for forward compatibility
     * @param string                                  $runId         Session/run identifier
     * @param string                                  $generatedAt   ISO-8601 generation timestamp
     * @param int                                     $generation    Monotonic generation counter
     * @param string|null                             $configHash    Hash of the merged MCP config for invalidation
     * @param array<string, McpServerCatalogEntryDTO> $servers       Server entries keyed by server name
     */
    public function __construct(
        public int $schemaVersion = 1,
        public string $runId = '',
        public string $generatedAt = '',
        public int $generation = 0,
        public ?string $configHash = null,
        public array $servers = [],
    ) {
    }

    /**
     * Serialize to the catalog JSON file shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serverArrays = [];
        foreach ($this->servers as $name => $entry) {
            $serverArrays[$name] = $entry->toArray();
        }

        return [
            'schemaVersion' => $this->schemaVersion,
            'runId' => $this->runId,
            'generatedAt' => $this->generatedAt,
            'generation' => $this->generation,
            'configHash' => $this->configHash,
            'servers' => $serverArrays,
        ];
    }

    /**
     * Hydrate from a decoded catalog JSON array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $servers = [];
        if (isset($data['servers']) && \is_array($data['servers'])) {
            foreach ($data['servers'] as $name => $serverData) {
                if (\is_array($serverData)) {
                    $servers[(string) $name] = McpServerCatalogEntryDTO::fromArray($serverData);
                }
            }
        }

        return new self(
            schemaVersion: isset($data['schemaVersion']) ? (int) $data['schemaVersion'] : 1,
            runId: (string) ($data['runId'] ?? ''),
            generatedAt: (string) ($data['generatedAt'] ?? ''),
            generation: isset($data['generation']) ? (int) $data['generation'] : 0,
            configHash: isset($data['configHash']) && \is_string($data['configHash'])
                ? $data['configHash']
                : null,
            servers: $servers,
        );
    }

    /**
     * Create an empty/failed catalog for a run with no valid servers.
     *
     * Used when config load fails or no servers are configured — ensures
     * stale tools from a previous catalog are not silently retained.
     */
    public static function empty(string $runId, int $generation = 1, ?string $configHash = null): self
    {
        return new self(
            schemaVersion: 1,
            runId: $runId,
            generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            generation: $generation,
            configHash: $configHash,
            servers: [],
        );
    }
}
