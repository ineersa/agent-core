<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Catalog;

/**
 * Maps MCP server/tool names to namespaced Hatfield tool identifiers
 * and provides reverse-lookup from Hatfield name to server + MCP tool.
 *
 * Default naming: `{server}_{tool}`
 *
 * Sanitization: allows letters, numbers, underscore, hyphen.
 * Any other character is replaced with underscore.
 */
final class McpToolNameMapper
{
    /**
     * Map a single tool to its Hatfield name.
     *
     * @return string Sanitized Hatfield tool name (e.g. "filesystem_read_file")
     */
    public function mapHatfieldName(string $serverName, string $mcpToolName): string
    {
        return $this->sanitize($serverName).'_'.$this->sanitize($mcpToolName);
    }

    /**
     * Sanitize a name component for use in LLM tool identifiers.
     *
     * Replacement rules:
     *  - Allow: a-z, A-Z, 0-9, underscore, hyphen
     *  - Replace any other character with underscore
     *  - Collapse consecutive underscores
     *  - Trim leading/trailing underscores
     *  - Ensure non-empty result
     */
    public function sanitize(string $name): string
    {
        if ('' === $name) {
            return 'unknown';
        }

        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        if (null === $sanitized || '' === $sanitized) {
            return 'unknown';
        }

        // Collapse consecutive underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized);

        // Trim leading/trailing underscores
        $sanitized = trim($sanitized, '_');

        if ('' === $sanitized) {
            return 'unknown';
        }

        return $sanitized;
    }

    /**
     * Build a reverse-mapping key for the catalog.
     *
     * The reverse mapping is embedded in McpToolDefinitionDTO through
     * serverName + mcpName fields. This method exists for callers that
     * need a standalone reverse-lookup key.
     *
     * @return string Reverse lookup key "{serverName}:{mcpToolName}"
     */
    public function reverseKey(string $serverName, string $mcpToolName): string
    {
        return $serverName.':'.$mcpToolName;
    }
}
