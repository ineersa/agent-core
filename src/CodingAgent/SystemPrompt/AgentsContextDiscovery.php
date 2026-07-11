<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\SystemPrompt;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Psr\Log\LoggerInterface;

/**
 * Discovers AGENTS.md project context files for new-session injection.
 *
 * Discovery order:
 *   1. Global directories (checked in order):
 *        ~/.hatfield/AGENTS.md or AGENTS.MD
 *        ~/.agents/AGENTS.md or AGENTS.MD
 *   2. Ancestor walk from {cwd} upward to filesystem root.
 *      For each directory (nearest first), check in order:
 *        {dir}/.hatfield/AGENTS.md or AGENTS.MD
 *        {dir}/.agents/AGENTS.md or AGENTS.MD
 *        {dir}/AGENTS.md or AGENTS.MD (bare project root)
 *      First match per directory wins; bare root is only checked when
 *      neither .hatfield/ nor .agents/ entry exists in that directory.
 *
 * Deduplication by resolved realpath() ensures the same file
 * is not returned twice even if reachable through multiple paths.
 *
 * Only AGENTS.md and AGENTS.MD are supported. Per subdirectory,
 * AGENTS.md is checked first; if found, AGENTS.MD is not checked
 * in that subdirectory.
 *
 * This class lives in CodingAgent because it depends on AppConfig
 * and SettingsPathResolver (CodingAgent-owned). AgentCore and TUI
 * must not depend on it.
 */
final readonly class AgentsContextDiscovery
{
    private const FILENAMES = ['AGENTS.md', 'AGENTS.MD'];
    private const SUBDIRECTORIES = ['.hatfield', '.agents'];

    public function __construct(
        private SettingsPathResolver $pathResolver,
        private AppConfig $appConfig,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Discover AGENTS.md files.
     *
     * Returns discovered files in order: global first, then nearest-to-farthest
     * ancestors from {cwd}. Each entry contains the resolved absolute path and
     * file content.
     *
     * @return list<array{path: string, content: string}>
     *
     * @throws \RuntimeException When CWD is not configured
     */
    public function discover(): array
    {
        $results = [];
        foreach ($this->collectDiscoveredPathEntries() as $entry) {
            $content = $this->readFile($entry['filePath']);
            if (null !== $content) {
                $results[] = ['path' => $entry['path'], 'content' => $content];
            }
        }

        return $results;
    }

    /**
     * Discover AGENTS.md paths only (no file reads).
     *
     * For display-only provenance such as the TUI loaded-resources block.
     *
     * @return list<array{path: string}>
     *
     * @throws \RuntimeException When CWD is not configured
     */
    public function discoverPaths(): array
    {
        $entries = [];
        foreach ($this->collectDiscoveredPathEntries() as $entry) {
            $entries[] = ['path' => $entry['path']];
        }

        return $entries;
    }

    /**
     * @return list<array{path: string, filePath: string}>
     */
    private function collectDiscoveredPathEntries(): array
    {
        $cwd = $this->resolveCwd();

        $results = [];
        /** @var array<string, bool> $seenPaths */
        $seenPaths = [];

        // Step 1: Check global directories (~/.hatfield/, ~/.agents/)
        $globalFile = $this->findInDirectory($this->pathResolver->getHomeDir(), includeBareRoot: false);
        if (null !== $globalFile) {
            $realPath = $this->realpath($globalFile);
            if (!isset($seenPaths[$realPath])) {
                $results[] = ['path' => $realPath, 'filePath' => $globalFile];
                $seenPaths[$realPath] = true;
            }
        }

        // Step 2: Walk upward from cwd to filesystem root, nearest first.
        $current = $cwd;

        while (true) {
            $found = $this->findInDirectory($current, includeBareRoot: true);
            if (null !== $found) {
                $realPath = $this->realpath($found);
                if (!isset($seenPaths[$realPath])) {
                    $results[] = ['path' => $realPath, 'filePath' => $found];
                    $seenPaths[$realPath] = true;
                }
            }

            $parent = \dirname($current);
            if ($parent === $current) {
                break; // Reached filesystem root
            }
            $current = $parent;
        }

        return $results;
    }

    /**
     * Find the first matching AGENTS.md or AGENTS.MD in the given base directory.
     *
     * Searches .hatfield/ then .agents/ subdirectories, then bare {baseDir}/AGENTS.md.
     * Within each location, checks AGENTS.md before AGENTS.MD.
     * Global home discovery omits bare ~/AGENTS.md (only ~/.hatfield and ~/.agents).
     */
    private function findInDirectory(string $baseDir, bool $includeBareRoot = true): ?string
    {
        $base = rtrim($baseDir, '/');

        foreach (self::SUBDIRECTORIES as $subdir) {
            foreach (self::FILENAMES as $filename) {
                $path = $base.'/'.$subdir.'/'.$filename;
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        if ($includeBareRoot) {
            foreach (self::FILENAMES as $filename) {
                $path = $base.'/'.$filename;
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the canonical realpath, falling back to the given path.
     */
    private function realpath(string $path): string
    {
        $real = realpath($path);

        return false !== $real ? $real : $path;
    }

    /**
     * Read file contents, returning null on failure.
     *
     * Logs a warning via the injected PSR-3 logger when available.
     */
    private function readFile(string $path): ?string
    {
        $content = file_get_contents($path);

        if (false === $content) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed to read AGENTS.md file: {path}', ['path' => $path]);
            }

            return null;
        }

        return $content;
    }

    /**
     * Resolve CWD from AppConfig, throwing if not configured.
     */
    private function resolveCwd(): string
    {
        if ('' === $this->appConfig->cwd) {
            throw new \RuntimeException('CWD is not configured. Ensure AppConfig::$cwd is set.');
        }

        return rtrim($this->appConfig->cwd, '/');
    }
}
