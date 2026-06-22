<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Psr\Log\LoggerInterface;

/**
 * Discovers agent definitions from all configured locations.
 *
 * Discovery precedence (highest wins — later layers override earlier):
 *   1. User agents under ~/.hatfield/agents/*.md
 *   2. User agents under ~/.agents/*.md
 *   3. Project agents under .hatfield/agents/*.md
 *   4. Project agents under .agents/*.md
 *   5. Configured agents.paths (additional explicit paths, highest precedence)
 *
 * Each directory is scanned non-recursively for *.md files (sorted
 * lexicographically for deterministic output). Explicit configured paths
 * may be a single .md file or a directory of *.md files.
 *
 * On name collision, the higher-precedence definition wins and an
 * override diagnostic is recorded with winner/loser paths.
 *
 * Auto-discovery missing dirs are silently skipped. Explicit configured
 * missing paths produce an actionable diagnostic. Invalid definition files
 * produce diagnostics; one invalid file does not abort all discovery.
 *
 * Disabled definitions (disabled: true) are still stored in the catalog
 * but excluded from enabled()/requireEnabled() lookups.
 *
 * Caches the result for the lifetime of this instance.
 *
 * @internal
 */
final class AgentDefinitionDiscovery
{
    private ?AgentDefinitionCatalog $cachedCatalog = null;

    public function __construct(
        private readonly AgentsConfig $agentsConfig,
        private readonly SettingsPathResolver $pathResolver,
        private readonly AgentDefinitionParser $parser,
        private readonly string $cwd,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Discover all agent definitions, caching the result.
     */
    public function discover(): AgentDefinitionCatalog
    {
        if (null !== $this->cachedCatalog) {
            return $this->cachedCatalog;
        }

        if (!$this->agentsConfig->enabled) {
            $this->cachedCatalog = new AgentDefinitionCatalog(definitions: [], diagnostics: []);

            return $this->cachedCatalog;
        }

        $homeDir = $this->pathResolver->getHomeDir();
        $cwd = rtrim($this->cwd, '/');

        /** @var array<string, AgentDefinitionDTO> $definitionsByName */
        $definitionsByName = [];
        /** @var list<AgentDefinitionDiagnosticDTO> $diagnostics */
        $diagnostics = [];

        // 1. User ~/.hatfield/agents
        $this->loadDirectory(
            $homeDir.'/.hatfield/agents',
            $definitionsByName,
            $diagnostics,
            isAutoDiscovery: true,
        );

        // 2. User ~/.agents
        $this->loadDirectory(
            $homeDir.'/.agents',
            $definitionsByName,
            $diagnostics,
            isAutoDiscovery: true,
        );

        // 3. Project .hatfield/agents
        $this->loadDirectory(
            $cwd.'/.hatfield/agents',
            $definitionsByName,
            $diagnostics,
            isAutoDiscovery: true,
        );

        // 4. Project .agents
        $this->loadDirectory(
            $cwd.'/.agents',
            $definitionsByName,
            $diagnostics,
            isAutoDiscovery: true,
        );

        // 5. Configured agents.paths (highest precedence)
        foreach ($this->agentsConfig->paths as $path) {
            $this->loadPath($path, $definitionsByName, $diagnostics);
        }

        $this->cachedCatalog = new AgentDefinitionCatalog(
            array_values($definitionsByName),
            $diagnostics,
        );

        return $this->cachedCatalog;
    }

    /**
     * Load all *.md files from a directory non-recursively.
     *
     * Missing auto-discovery dirs are silently skipped.
     *
     * @param array<string, AgentDefinitionDTO>  $definitionsByName
     * @param list<AgentDefinitionDiagnosticDTO> $diagnostics
     */
    private function loadDirectory(
        string $dir,
        array &$definitionsByName,
        array &$diagnostics,
        bool $isAutoDiscovery = false,
    ): void {
        if (!is_dir($dir)) {
            if (!$isAutoDiscovery) {
                $diagnostics[] = new AgentDefinitionDiagnosticDTO(
                    type: 'missing_path',
                    message: \sprintf('Agent definition directory not found: %s', $dir),
                    path: $dir,
                );
            }

            return;
        }

        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }

        // Sort lexically for deterministic output.
        sort($entries);

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $filePath = rtrim($dir, '/').'/'.$entry;

            if (!is_file($filePath)) {
                continue;
            }

            // Only exact .md suffix, case-sensitive.
            if (!str_ends_with($entry, '.md')) {
                continue;
            }

            $this->loadFile($filePath, $definitionsByName, $diagnostics);
        }
    }

    /**
     * Load a single explicit path (file or directory).
     *
     * @param array<string, AgentDefinitionDTO>  $definitionsByName
     * @param list<AgentDefinitionDiagnosticDTO> $diagnostics
     */
    private function loadPath(
        string $path,
        array &$definitionsByName,
        array &$diagnostics,
    ): void {
        if (!file_exists($path)) {
            $diagnostics[] = new AgentDefinitionDiagnosticDTO(
                type: 'missing_path',
                message: \sprintf('Agent definition path not found: %s', $path),
                path: $path,
            );

            return;
        }

        if (is_dir($path)) {
            $this->loadDirectory($path, $definitionsByName, $diagnostics, isAutoDiscovery: false);
        } elseif (is_file($path)) {
            if (str_ends_with(basename($path), '.md')) {
                $this->loadFile($path, $definitionsByName, $diagnostics);
            } else {
                $diagnostics[] = new AgentDefinitionDiagnosticDTO(
                    type: 'invalid_path',
                    message: \sprintf('Configured agent path is not a .md file: %s', $path),
                    path: $path,
                );
            }
        }
    }

    /**
     * Load a single .md definition file.
     *
     * Higher-precedence definitions override lower-precedence ones by name.
     * Invalid files produce a diagnostic and do not abort discovery.
     *
     * @param array<string, AgentDefinitionDTO>  $definitionsByName
     * @param list<AgentDefinitionDiagnosticDTO> $diagnostics
     */
    private function loadFile(
        string $filePath,
        array &$definitionsByName,
        array &$diagnostics,
    ): void {
        try {
            $definition = $this->parser->parseFile($filePath);
        } catch (AgentDefinitionValidationException $e) {
            $diagnostics[] = new AgentDefinitionDiagnosticDTO(
                type: 'invalid_definition',
                message: $e->getMessage(),
                path: $filePath,
            );

            if (null !== $this->logger) {
                $this->logger->warning('Agent definition parse error: {error}', [
                    'error' => $e->getMessage(),
                    'path' => $filePath,
                ]);
            }

            return;
        } catch (\Throwable $e) {
            $diagnostics[] = new AgentDefinitionDiagnosticDTO(
                type: 'invalid_definition',
                message: \sprintf('Unexpected error parsing agent definition "%s": %s', $filePath, $e->getMessage()),
                path: $filePath,
            );

            if (null !== $this->logger) {
                $this->logger->warning('Unexpected agent definition error: {error}', [
                    'error' => $e->getMessage(),
                    'path' => $filePath,
                    'exception' => $e,
                ]);
            }

            return;
        }

        $name = $definition->name;

        if (isset($definitionsByName[$name])) {
            $diagnostics[] = new AgentDefinitionDiagnosticDTO(
                type: 'collision',
                message: \sprintf(
                    'Agent name collision: "%s" from "%s" overrides "%s".',
                    $name,
                    $filePath,
                    $definitionsByName[$name]->sourcePath,
                ),
                path: $filePath,
                name: $name,
                winnerPath: $filePath,
                loserPath: $definitionsByName[$name]->sourcePath,
            );
        }

        // Last-write wins: higher-precedence layers override.
        $definitionsByName[$name] = $definition;
    }
}
