<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers skills from configured search paths.
 *
 * Discovery order (highest priority first):
 *   1. CLI --skills-path entries (always checked, regardless of --no-skills)
 *   2. Auto-discovery paths (only when auto-discovery is enabled):
 *        {cwd}/.hatfield/skills
 *        {cwd}/.agents/skills
 *        ~/.hatfield/skills
 *        ~/.agents/skills
 *
 * Each path is scanned recursively for SKILL.md files. When a directory
 * contains SKILL.md, that directory is treated as a skill root and its
 * subdirectories are NOT scanned for additional skills.
 *
 * On name collision, the first-discovered skill wins. Collision diagnostics
 * are recorded and logged.
 *
 * Discovery is lazy — the first discover() call reads from SkillsConfig,
 * which is populated by AgentCommand after CLI option parsing.
 */
final class SkillDiscovery
{
    private const AUTO_DISCOVERY_PATTERNS = [
        '%s/.hatfield/skills',
        '%s/.agents/skills',
    ];

    private const MAX_RECURSION_DEPTH = 20;

    /** @var list<SkillDefinition>|null */
    private ?array $cachedResult = null;

    /** @var list<array{winner: string, ignored: string, name: string}> */
    private array $collisions = [];

    public function __construct(
        private readonly SkillsConfig $config,
        private readonly SettingsPathResolver $pathResolver,
        private readonly AppConfig $appConfig,
        private readonly MarkdownFrontmatterExtractor $extractor,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Discover all skills from configured paths.
     *
     * Caches results for the lifetime of this instance.
     *
     * @return list<SkillDefinition>
     *
     * @throws \RuntimeException When CWD is not configured
     */
    public function discover(): array
    {
        if (null !== $this->cachedResult) {
            return $this->cachedResult;
        }

        $cwd = $this->resolveCwd();
        $homeDir = $this->pathResolver->getHomeDir();

        /** @var list<string> $searchPaths */
        $searchPaths = [];

        // Step 1: CLI --skills-path entries (highest priority, always checked)
        foreach ($this->config->skillsPaths as $path) {
            $searchPaths[] = $path;
        }

        // Step 2: Auto-discovery paths (only when auto-discovery is enabled)
        if (!$this->config->noSkills) {
            $baseDirs = [$cwd, $homeDir];

            foreach ($baseDirs as $baseDir) {
                foreach (self::AUTO_DISCOVERY_PATTERNS as $pattern) {
                    $path = \sprintf($pattern, $baseDir);
                    if (is_dir($path)) {
                        $searchPaths[] = $path;
                    }
                }
            }
        }

        // Scan all search paths
        $skills = [];
        $this->collisions = [];
        /** @var array<string, string> $seenNames name → first-seen skill dir */
        $seenNames = [];

        foreach ($searchPaths as $searchPath) {
            $foundRoots = $this->scanForSkillRoots($searchPath, 0);

            foreach ($foundRoots as $skillDir) {
                $definition = $this->buildDefinition($skillDir);
                if (null === $definition) {
                    continue;
                }

                $name = $definition->name;

                if (isset($seenNames[$name])) {
                    $this->collisions[] = [
                        'winner' => $seenNames[$name],
                        'ignored' => $skillDir,
                        'name' => $name,
                    ];
                    if (null !== $this->logger) {
                        $this->logger->warning('Skill name collision: "{name}" already registered from "{winner}", ignoring "{ignored}"', [
                            'name' => $name,
                            'winner' => $seenNames[$name],
                            'ignored' => $skillDir,
                        ]);
                    }
                    continue;
                }

                $skills[] = $definition;
                $seenNames[$name] = $skillDir;
            }
        }

        $this->cachedResult = $skills;

        return $skills;
    }

    /**
     * @return list<array{winner: string, ignored: string, name: string}>
     */
    public function getCollisions(): array
    {
        // Ensure discovery has run
        $this->discover();

        return $this->collisions;
    }

    /**
     * Recursively scan a directory for skill roots (directories containing SKILL.md).
     *
     * @return list<string> Absolute paths to skill root directories
     */
    private function scanForSkillRoots(string $dir, int $depth): array
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            if (null !== $this->logger) {
                $this->logger->warning('Skill discovery recursion depth limit reached at {dir}', ['dir' => $dir]);
            }

            return [];
        }

        $realDir = realpath($dir);

        if (false === $realDir || !is_dir($realDir)) {
            return [];
        }

        // Check if this directory itself is a skill root
        $skillMdPath = $realDir.'/SKILL.md';
        if (is_file($skillMdPath)) {
            return [$realDir];
        }

        // Otherwise, scan immediate subdirectories
        $results = [];
        $entries = scandir($realDir);

        if (false === $entries) {
            return [];
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $childPath = $realDir.'/'.$entry;

            if (!is_dir($childPath)) {
                continue;
            }

            $subResults = $this->scanForSkillRoots($childPath, $depth + 1);
            $results = array_merge($results, $subResults);
        }

        return $results;
    }

    /**
     * Build a SkillDefinition from a skill root directory.
     *
     * Reads and parses SKILL.md frontmatter to extract name, description,
     * and model invocation settings.
     */
    private function buildDefinition(string $skillDir): ?SkillDefinition
    {
        $skillFile = $skillDir.'/SKILL.md';

        if (!is_file($skillFile)) {
            return null;
        }

        $content = file_get_contents($skillFile);

        if (false === $content) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed to read SKILL.md at {path}', ['path' => $skillFile]);
            }

            return null;
        }

        $frontmatter = $this->parseFrontmatter($content);

        $name = $frontmatter['name'] ?? basename($skillDir);
        $description = $frontmatter['description'] ?? '';
        $disableModelInvocation = (bool) ($frontmatter['disable-model-invocation'] ?? false);

        return new SkillDefinition(
            name: $name,
            description: $description,
            skillFile: $skillFile,
            skillDirectory: $skillDir,
            modelInvocationEnabled: !$disableModelInvocation,
        );
    }

    /**
     * Parse YAML frontmatter from SKILL.md content.
     *
     * Uses the shared {@see MarkdownFrontmatterExtractor} for delimiter scanning
     * (BOM handling, proper delimiter-line detection, \n---/\n... closers).
     *
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $content): array
    {
        $extraction = $this->extractor->extract($content);

        if (null === $extraction['yamlBlock']) {
            return [];
        }

        try {
            $parsed = Yaml::parse($extraction['yamlBlock']);

            return \is_array($parsed) ? $parsed : [];
        } catch (\Throwable $e) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed to parse SKILL.md frontmatter YAML', [
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        }
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
