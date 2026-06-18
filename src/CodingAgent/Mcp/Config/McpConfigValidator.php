<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

/**
 * Validates MCP server definitions from raw config data.
 *
 * Checks transport completeness, enabled/disabled semantics,
 * field-level type correctness, and slot-level invariants such
 * as the command+url exclusivity rule.
 *
 * This validator runs on raw data before the DTOs are built,
 * so errors reference the original JSON keys and shape.
 */
final class McpConfigValidator
{
    /**
     * Validate the raw mcpServers map from parsed JSON.
     *
     * @param array<string, array<string, mixed>> $rawServers    Parsed mcpServers data
     * @param array<string, mixed>|null           $globalServers Previously-loaded global servers (for inherited disable checks), or null if loading global config
     *
     * @return array<string, array<string, mixed>> The validated raw data (unchanged, throws on failure)
     *
     * @throws \RuntimeException for any invalid configuration
     */
    public function validate(array $rawServers, ?array $globalServers = null): array
    {
        foreach ($rawServers as $name => $data) {
            if (!\is_string($name) || '' === $name) {
                throw new \RuntimeException('MCP config: server name must be a non-empty string.');
            }

            if (!\is_array($data)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": server definition must be an object.', $name));
            }

            $this->validateServer($name, $data, $globalServers);
        }

        return $rawServers;
    }

    /**
     * @param array<string, mixed>      $data          Server raw data
     * @param array<string, mixed>|null $globalServers Global server definitions for inherited disable check
     */
    private function validateServer(string $name, array $data, ?array $globalServers): void
    {
        // Check for unexpected fields
        $allowedFields = [
            'enabled', 'command', 'args', 'env', 'cwd',
            'url', 'headers', 'timeoutMs', 'startupTimeoutMs', 'excludeTools',
        ];

        foreach (array_keys($data) as $key) {
            if (!\in_array($key, $allowedFields, true)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": unknown field "%s". Allowed fields: %s.', $name, $key, implode(', ', $allowedFields)));
            }
        }

        // Validate enabled is boolean if present
        if (\array_key_exists('enabled', $data) && !\is_bool($data['enabled'])) {
            throw new \RuntimeException(\sprintf('MCP server "%s": "enabled" must be a boolean, got %s.', $name, \gettype($data['enabled'])));
        }

        $enabled = $data['enabled'] ?? true;

        $hasCommand = isset($data['command']);
        $hasUrl = isset($data['url']);

        // Both command and url defined → invalid
        if ($hasCommand && $hasUrl) {
            throw new \RuntimeException(\sprintf('MCP server "%s": cannot define both "command" (STDIO) and "url" (HTTP). Choose exactly one transport.', $name));
        }

        // No transport defined
        if (!$hasCommand && !$hasUrl) {
            // Special case: inherited disable-only override
            if (null !== $globalServers && \array_key_exists($name, $globalServers)) {
                // Project override only has enabled:false for an inherited server → valid
                if (\array_key_exists('enabled', $data) && false === $data['enabled']) {
                    return;
                }

                throw new \RuntimeException(\sprintf('MCP server "%s": missing transport (command or url). An inherited server override must define a transport or explicitly set "enabled": false.', $name));
            }

            // Non-inherited disable-only entry → invalid
            if (!$enabled) {
                throw new \RuntimeException(\sprintf('MCP server "%s": cannot define a server with only "enabled": false and no transport. This server is not inherited from global config.', $name));
            }

            // Non-inherited enabled server with no transport → invalid
            throw new \RuntimeException(\sprintf('MCP server "%s": missing transport. Define "command" for a STDIO server or "url" for an HTTP server.', $name));
        }

        // Validate command is non-empty string (checked by DTO fromArray but validate early for consistency)
        if ($hasCommand) {
            if (!\is_string($data['command']) || '' === $data['command']) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "command" must be a non-empty string.', $name));
            }
        }

        // Validate url is non-empty string
        if ($hasUrl) {
            if (!\is_string($data['url']) || '' === $data['url']) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "url" must be a non-empty string.', $name));
            }
        }

        // Validate timeoutMs is positive int if present
        if (\array_key_exists('timeoutMs', $data)) {
            if (!\is_int($data['timeoutMs']) || $data['timeoutMs'] < 1) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "timeoutMs" must be a positive integer, got %s.', $name, \is_int($data['timeoutMs']) ? (string) $data['timeoutMs'] : \gettype($data['timeoutMs'])));
            }
        }

        // Validate startupTimeoutMs is positive int if present
        if (\array_key_exists('startupTimeoutMs', $data)) {
            if (!\is_int($data['startupTimeoutMs']) || $data['startupTimeoutMs'] < 1) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "startupTimeoutMs" must be a positive integer, got %s.', $name, \is_int($data['startupTimeoutMs']) ? (string) $data['startupTimeoutMs'] : \gettype($data['startupTimeoutMs'])));
            }
        }
    }
}
