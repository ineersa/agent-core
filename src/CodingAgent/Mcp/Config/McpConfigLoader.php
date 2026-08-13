<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

use Ineersa\CodingAgent\Config\SettingsPathResolver;

/**
 * Loads typed MCP configuration from global and project .hatfield/mcp.json files.
 *
 * Owns trust-boundary normalization/validation and env/header interpolation.
 *
 * Merge semantics:
 *  - Project server definitions replace whole global definitions by server name.
 *  - A project server with only `{ "enabled": false }` disables an inherited server.
 *  - Non-inherited disable-only entries fail validation.
 *
 * Empty or missing files produce an empty McpConfigDTO (no error).
 *
 * This loader is designed as a standalone service with explicit dependencies
 * (SettingsPathResolver and project CWD) rather than relying on DI autowiring
 * of the full container, so it can be tested easily without kernel boot.
 */
final class McpConfigLoader
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
        private readonly string $projectCwd,
    ) {
    }

    /**
     * Load the merged MCP configuration.
     *
     * Reads ~/.hatfield/mcp.json (global) then <cwd>/.hatfield/mcp.json (project).
     * Merges by whole-server replacement, validates, interpolates env vars,
     * and returns a typed McpConfigDTO.
     *
     * @throws \RuntimeException for validation or interpolation failures
     */
    public function load(): McpConfigDTO
    {
        $globalRaw = $this->loadJsonFile('~/.hatfield/mcp.json', $this->pathResolver->getHomeDir());
        $projectRaw = $this->loadJsonFile($this->projectCwd.'/.hatfield/mcp.json', $this->projectCwd);

        $globalServers = $this->extractServers($globalRaw, '~/.hatfield/mcp.json');
        if ([] !== $globalServers) {
            $globalServers = $this->validate($globalServers, null);
        }

        $projectServers = $this->extractServers($projectRaw, $this->projectCwd.'/.hatfield/mcp.json');
        if ([] !== $projectServers) {
            $projectServers = $this->validate($projectServers, $globalServers);
        }

        // Merge: project overrides global by whole-server replacement
        $mergedRaw = array_merge($globalServers, $projectServers);

        // Remove any server with enabled:false from the final config
        foreach ($mergedRaw as $name => $data) {
            if (\is_array($data) && ($data['enabled'] ?? true) === false) {
                unset($mergedRaw[$name]);
            }
        }

        // Build typed DTOs with interpolation
        $servers = [];
        foreach ($mergedRaw as $name => $data) {
            if (!\is_array($data)) {
                continue;
            }

            // Interpolate env and headers BEFORE building DTO
            if (isset($data['env']) && \is_array($data['env'])) {
                /** @var array<string, string> $env */
                $env = $data['env'];
                $data['env'] = $this->interpolateMap($env, $name, 'env');
            }

            if (isset($data['headers']) && \is_array($data['headers'])) {
                /** @var array<string, string> $headers */
                $headers = $data['headers'];
                $data['headers'] = $this->interpolateMap($headers, $name, 'headers');
            }

            // Resolve relative cwd to project CWD
            if (isset($data['cwd']) && \is_string($data['cwd']) && '' !== $data['cwd']) {
                $data['cwd'] = $this->resolveCwd($data['cwd']);
            }

            $servers[$name] = McpServerDefinitionDTO::fromArray($name, $data);
        }

        return new McpConfigDTO(servers: $servers);
    }

    /**
     * Load and decode a JSON file, returning the decoded array or empty array on missing/invalid.
     *
     * @return array<string, mixed>
     */
    private function loadJsonFile(string $pathPattern, string $baseDir): array
    {
        $resolved = $this->pathResolver->resolve($pathPattern, $baseDir);

        $content = @file_get_contents($resolved);

        if (false === $content) {
            return [];
        }

        $decoded = json_decode($content, true);

        if (\JSON_ERROR_NONE !== json_last_error()) {
            throw new \RuntimeException(\sprintf('MCP config file "%s" is not valid JSON: %s.', $resolved, json_last_error_msg()));
        }

        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('MCP config file "%s" must contain a JSON object.', $resolved));
        }

        return $decoded;
    }

    /**
     * Extract mcpServers from a decoded JSON config.
     *
     * Returns empty array when the key is missing (normal: no servers configured).
     * Throws when the key is present but not a JSON object/array.
     *
     * @param array<string, mixed> $raw    Decoded JSON config
     * @param string               $source Human-readable file path for error messages
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when mcpServers is present but not a JSON object
     */
    private function extractServers(array $raw, string $source): array
    {
        if (!\array_key_exists('mcpServers', $raw)) {
            return [];
        }

        $servers = $raw['mcpServers'];

        if (!\is_array($servers)) {
            throw new \RuntimeException(\sprintf('MCP config file "%s": "mcpServers" must be a JSON object, got %s.', $source, \gettype($servers)));
        }

        return $servers;
    }

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
    private function validate(array $rawServers, ?array $globalServers = null): array
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
            'url', 'headers', 'timeoutMs', 'startupTimeoutMs', 'availability', 'excludeTools',
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

        if (\array_key_exists('availability', $data)) {
            if (!\is_string($data['availability']) || !\in_array($data['availability'], ['all', 'specific'], true)) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "availability" must be one of: all, specific.', $name));
            }
        }

        // Validate startupTimeoutMs is positive int if present
        if (\array_key_exists('startupTimeoutMs', $data)) {
            if (!\is_int($data['startupTimeoutMs']) || $data['startupTimeoutMs'] < 1) {
                throw new \RuntimeException(\sprintf('MCP server "%s": "startupTimeoutMs" must be a positive integer, got %s.', $name, \is_int($data['startupTimeoutMs']) ? (string) $data['startupTimeoutMs'] : \gettype($data['startupTimeoutMs'])));
            }
        }
    }

    /**
     * Interpolate `${VAR}` references in all values of a string map.
     *
     * Missing or empty interpolated variables are configuration errors.
     * Literal empty-string values without `${...}` are allowed unchanged.
     * Error messages include var name and server/field context, never secret values.
     *
     * @param array<string, string> $map    The map to interpolate (e.g. env or headers)
     * @param string                $server Server name for error context
     * @param string                $field  Field name for error context (e.g. "env" or "headers")
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException when a referenced env var is missing or resolves to an empty string
     */
    private function interpolateMap(array $map, string $server, string $field): array
    {
        $result = [];

        foreach ($map as $key => $value) {
            $result[$key] = $this->interpolateValue($value, $server, \sprintf('%s.%s', $field, $key));
        }

        return $result;
    }

    /**
     * @throws \RuntimeException when a referenced env var is missing or empty
     */
    private function interpolateValue(string $value, string $server, string $context): string
    {
        // Fast path: no interpolation needed
        if (!str_contains($value, '${')) {
            return $value;
        }

        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use ($server, $context): string {
                $varName = $matches[1];
                $resolved = getenv($varName);

                if (false === $resolved) {
                    throw new \RuntimeException(\sprintf('MCP server "%s": environment variable "%s" referenced in "%s" is not set.', $server, $varName, $context));
                }

                if ('' === $resolved) {
                    throw new \RuntimeException(\sprintf('MCP server "%s": environment variable "%s" referenced in "%s" is empty. Set the variable or remove the interpolation reference.', $server, $varName, $context));
                }

                return $resolved;
            },
            $value,
        );
    }

    /**
     * Resolve a relative cwd to the project CWD.
     *
     * An empty string or null cwd is left as-is (means "use project cwd").
     * Absolute paths pass through unchanged.
     */
    private function resolveCwd(string $cwd): string
    {
        // Already absolute, return as-is
        if (str_starts_with($cwd, '/')) {
            return $cwd;
        }

        // Tilde expansion
        if (str_starts_with($cwd, '~')) {
            return $this->pathResolver->resolve($cwd, $this->projectCwd);
        }

        // Relative path → resolve against project cwd
        return $this->pathResolver->resolve($cwd, $this->projectCwd);
    }
}
