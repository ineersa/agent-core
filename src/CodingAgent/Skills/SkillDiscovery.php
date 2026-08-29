<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Path\PathResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers skills from configured search paths.
 *
 * Discovery order (highest priority first):
 *   1. CLI --skills-path entries (always checked, regardless of --no-skills)
 *   2. Auto-discovery paths (only when auto-discovery is enabled):
 *        {cwd}/.hatfield/skills
 *        ~/.hatfield/skills   (includes materialized built-in skills)
 *        {cwd}/.agents/skills
 *        ~/.agents/skills
 *   3. Extension-registered skill directories (only when auto-discovery is enabled)
 *
 * Built-in skills shipped under AppResourceLocator::getBuiltinSkillsPath()
 * are mirrored into ~/.hatfield/skills/<name>/ immediately before scanning
 * (including when --no-skills suppresses the scan). Hatfield owns those
 * destinations and rewrites them; sibling user skills are left untouched.
 *
 * Each path is scanned recursively for SKILL.md files. When a directory
 * contains SKILL.md, that directory is treated as a skill root and its
 * subdirectories are NOT scanned for additional skills.
 *
 * On name collision, the first-discovered skill wins. Collision diagnostics
 * are recorded and logged.
 *
 * Discovery is lazy — the first discover() call reads from SkillsConfig,
 * which is populated by AgentCommand after CLI option parsing. Extension
 * packages may also call registerSkill() during extension load; that
 * invalidates any cached result so the next discover() includes them.
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

    /** @var array<string, SkillDefinition> */
    private array $skillsByCommandName = [];

    /** @var list<string> Absolute skill directories registered by enabled extensions */
    private array $registeredSkillDirectories = [];

    public function __construct(
        private readonly SkillsConfig $config,
        private readonly SettingsPathResolver $pathResolver,
        private readonly AppConfig $appConfig,
        private readonly MarkdownFrontmatterExtractor $extractor,
        private readonly AppResourceLocator $resources,
        private readonly Filesystem $filesystem,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Register one absolute skill directory contributed by an enabled extension.
     *
     * Directories are scanned after CLI and project/user auto-discovery paths
     * and only when auto-discovery is enabled (not --no-skills).
     */
    public function registerSkill(string $skillDirectory): void
    {
        $this->registeredSkillDirectories[] = $skillDirectory;
        $this->cachedResult = null;
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

        // Materialize bundled skills into ~/.hatfield/skills before scanning.
        // Runs even when --no-skills suppresses auto-discovery so the home
        // copy stays current for later sessions.
        $this->materializeBuiltinSkills($homeDir);

        /** @var list<string> $searchPaths */
        $searchPaths = [];

        // Step 1: CLI --skills-path entries (highest priority, always checked)
        foreach ($this->config->skillsPaths as $path) {
            $searchPaths[] = $path;
        }

        // Step 2: Auto-discovery paths (only when auto-discovery is enabled).
        // Patterns are highest→lowest Hatfield specificity; for each pattern
        // scan project then user so Hatfield-specific beats generic scope.
        if (!$this->config->noSkills) {
            foreach (self::AUTO_DISCOVERY_PATTERNS as $pattern) {
                foreach ([$cwd, $homeDir] as $baseDir) {
                    $path = \sprintf($pattern, $baseDir);
                    if (is_dir($path)) {
                        $searchPaths[] = $path;
                    }
                }
            }

            // Step 3: Extension-registered skill directories (lowest precedence)
            foreach ($this->registeredSkillDirectories as $directory) {
                $searchPaths[] = $directory;
            }
        }

        // Scan all search paths
        $skills = [];
        $this->collisions = [];
        $this->skillsByCommandName = [];
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
                $this->skillsByCommandName[strtolower($name)] ??= $definition;
            }
        }

        $this->cachedResult = $skills;

        return $skills;
    }

    public function findByCommandName(string $name): ?SkillDefinition
    {
        $this->discover();

        return $this->skillsByCommandName[strtolower($name)] ?? null;
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
     * Look up the winning discovered skill whose SKILL.md matches $path.
     *
     * Accepts absolute paths (as emitted in skills context) and relative paths
     * resolved against AppConfig::$cwd via PathResolver. Only exact canonical
     * winners from {@see discover()} match — unrelated SKILL.md files,
     * collision losers, empty paths, and nonexistent paths return null.
     */
    public function findBySkillFilePath(string $path): ?SkillDefinition
    {
        $path = trim($path);
        if ('' === $path) {
            return null;
        }

        try {
            $resolved = PathResolver::resolve($path, $this->resolveCwd());
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // Intentional local degradation: invalid path input or missing CWD
            // means ordinary-read presentation, not a hard projection failure.
            if (null !== $this->logger) {
                $this->logger->warning('Skill path classification skipped', [
                    'component' => 'skills.discovery',
                    'event_type' => 'skill_path_classification_skipped',
                    'exception_class' => $e::class,
                ]);
            }

            return null;
        }

        $canonical = realpath($resolved);
        if (false === $canonical) {
            return null;
        }

        // skillFile is already absolute from discover()'s realpath()'d skill roots.
        foreach ($this->discover() as $skill) {
            if ($skill->skillFile === $canonical) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * Mirror every direct bundled skill directory into ~/.hatfield/skills/<name>/.
     *
     * Only direct children of the bundled skills root that contain SKILL.md are
     * treated as built-ins. Nested reference files stay inside those skill trees.
     * Owned destinations are removed before mirror so a previous PHAR/materialization
     * that left files mode 0444 cannot block whole-directory replacement. Sibling
     * user skill directories under ~/.hatfield/skills are left untouched.
     * Concurrent same-version copies are accepted as idempotent; an interrupted
     * write is repaired on the next discover().
     */
    private function materializeBuiltinSkills(string $homeDir): void
    {
        $sourceRoot = $this->resources->getBuiltinSkillsPath();
        if (!is_dir($sourceRoot)) {
            return;
        }

        $entries = scandir($sourceRoot);
        if (false === $entries) {
            return;
        }

        $destinationRoot = rtrim($homeDir, '/').'/.hatfield/skills';

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $sourceDir = $sourceRoot.'/'.$entry;
            if (!is_dir($sourceDir) || !is_file($sourceDir.'/SKILL.md')) {
                continue;
            }

            // Hatfield owns ~/.hatfield/skills/<name>/ for each built-in. Remove the
            // whole destination first: mirror(override/delete) cannot rewrite read-only
            // files left by PHAR extraction (mode 0444).
            $destinationDir = $destinationRoot.'/'.$entry;
            if ($this->filesystem->exists($destinationDir)) {
                $this->filesystem->remove($destinationDir);
            }

            $this->filesystem->mirror(
                $sourceDir,
                $destinationDir,
                null,
                ['override' => true, 'delete' => true],
            );
        }
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
