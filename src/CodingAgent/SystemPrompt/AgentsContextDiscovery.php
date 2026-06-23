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
 *   2. Ancestor walk from {cwd} upward to filesystem root,
 *      checking each directory for AGENTS.md or AGENTS.MD
 *      in .hatfield/ then .agents/ subdirectories.
 *      Nearest ancestor found first.
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
        private LlmProxyDeterministicPromptMode $llmProxyDeterministicPromptMode,
        private string $projectDir,
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
        $cwd = $this->resolveCwd();

        if ($this->llmProxyDeterministicPromptMode->enabled()) {
            return $this->discoverDeterministicFixture();
        }

        $results = [];
        /** @var array<string, bool> $seenPaths */
        $seenPaths = [];

        // Step 1: Check global directories (~/.hatfield/, ~/.agents/)
        $globalFile = $this->findInDirectory($this->pathResolver->getHomeDir());
        if (null !== $globalFile) {
            $realPath = $this->realpath($globalFile);
            if (!isset($seenPaths[$realPath])) {
                $content = $this->readFile($globalFile);
                if (null !== $content) {
                    $results[] = ['path' => $realPath, 'content' => $content];
                    $seenPaths[$realPath] = true;
                }
            }
        }

        // Step 2: Walk upward from cwd to filesystem root, nearest first.
        $current = $cwd;
        $ancestorFiles = [];

        while (true) {
            $found = $this->findInDirectory($current);
            if (null !== $found) {
                $realPath = $this->realpath($found);
                if (!isset($seenPaths[$realPath])) {
                    $ancestorFiles[] = ['path' => $realPath, 'content' => null, 'filePath' => $found];
                    $seenPaths[$realPath] = true;
                }
            }

            $parent = \dirname($current);
            if ($parent === $current) {
                break; // Reached filesystem root
            }
            $current = $parent;
        }

        // Read contents of ancestor files in a second pass.
        // Content is read after collecting all paths to minimize I/O —
        // only files that survive deduplication are read.
        foreach ($ancestorFiles as $item) {
            $content = $this->readFile($item['filePath']);
            if (null !== $content) {
                $results[] = ['path' => $item['path'], 'content' => $content];
            }
        }

        return $results;
    }


    /**
     * Stable AGENTS.md context for llama-proxy cache (repo-root AGENTS.md only).
     *
     * @return list<array{path: string, content: string}>
     */
    private function discoverDeterministicFixture(): array
    {
        $root = rtrim($this->projectDir, '/');
        if ('' === $root) {
            return [];
        }

        foreach (self::FILENAMES as $filename) {
            $rootFile = $root.'/'.$filename;
            if (is_file($rootFile)) {
                $content = $this->readFile($rootFile);
                if (null !== $content) {
                    return [
                        [
                            'path' => $this->llmProxyDeterministicPromptMode->fixedCwd().'/'.$filename,
                            'content' => $content,
                        ],
                    ];
                }
            }
        }

        foreach (self::SUBDIRECTORIES as $subdir) {
            foreach (self::FILENAMES as $filename) {
                $path = $root.'/'.$subdir.'/'.$filename;
                if (!is_file($path)) {
                    continue;
                }
                $content = $this->readFile($path);
                if (null === $content) {
                    return [];
                }

                return [
                    [
                        'path' => $this->llmProxyDeterministicPromptMode->fixedCwd().'/'.$subdir.'/'.$filename,
                        'content' => $content,
                    ],
                ];
            }
        }

        return [];
    }

    /**
     * Find the first matching AGENTS.md or AGENTS.MD in the given base directory.
     *
     * Searches .hatfield/ then .agents/ subdirectories. Within each subdirectory,
     * checks AGENTS.md first, then AGENTS.MD. Returns the resolved file path if
     * found, null otherwise.
     */
    private function findInDirectory(string $baseDir): ?string
    {
        foreach (self::SUBDIRECTORIES as $subdir) {
            foreach (self::FILENAMES as $filename) {
                $path = rtrim($baseDir, '/').'/'.$subdir.'/'.$filename;
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
