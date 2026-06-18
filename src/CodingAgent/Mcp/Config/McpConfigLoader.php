<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

use Ineersa\CodingAgent\Config\SettingsPathResolver;

/**
 * Loads typed MCP configuration from global and project .hatfield/mcp.json files.
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
        private readonly McpConfigValidator $validator,
        private readonly McpEnvInterpolator $interpolator,
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

        // Validate raw configs
        $globalServers = $globalRaw['mcpServers'] ?? [];
        if (\is_array($globalServers) && [] !== $globalServers) {
            $globalServers = $this->validator->validate($globalServers, null);
        } else {
            $globalServers = [];
        }

        $projectServers = $projectRaw['mcpServers'] ?? [];
        if (\is_array($projectServers) && [] !== $projectServers) {
            $projectServers = $this->validator->validate($projectServers, $globalServers);
        } else {
            $projectServers = [];
        }

        // Merge: project overrides global by whole-server replacement
        $mergedRaw = array_merge($globalServers, $projectServers);

        // Apply disable-only overrides: remove servers where project says enabled:false
        foreach ($projectServers as $name => $projData) {
            if (\is_array($projData) && ($projData['enabled'] ?? true) === false) {
                unset($mergedRaw[$name]);
            }
        }

        // Also remove globally-disabled servers
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
                $data['env'] = $this->interpolator->interpolateMap($env, $name, 'env');
            }

            if (isset($data['headers']) && \is_array($data['headers'])) {
                /** @var array<string, string> $headers */
                $headers = $data['headers'];
                $data['headers'] = $this->interpolator->interpolateMap($headers, $name, 'headers');
            }

            // Resolve relative cwd to project CWD
            if (isset($data['cwd']) && \is_string($data['cwd']) && '' !== $data['cwd']) {
                $data['cwd'] = $this->resolveCwd($data['cwd']);
            }

            $servers[$name] = McpServerDefinitionDTO::fromArray($name, $data);
        }

        return McpConfigDTO::fromServers($servers);
    }

    /**
     * Load and decode a JSON file, returning the decoded array or empty array on missing/invalid.
     *
     * @return array<string, mixed>
     */
    private function loadJsonFile(string $pathPattern, string $baseDir): array
    {
        $resolved = $this->pathResolver->resolve($pathPattern, $baseDir);

        if (!is_file($resolved)) {
            return [];
        }

        $content = file_get_contents($resolved);

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
