<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\Exception\SkillFrontmatterValidationException;
use Ineersa\CodingAgent\Skills\Validation\SkillFrontmatterValidator;
use Ineersa\Hatfield\ExtensionApi\Path\PathResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
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
 * (including when --no-skills suppresses the scan). Only pristine installed
 * copies are upgraded; modified or deleted copies stay under user control.
 *
 * Each path is scanned recursively for SKILL.md files. When a directory
 * contains SKILL.md, that directory is treated as a skill root and its
 * subdirectories are NOT scanned for additional skills.
 *
 * Skill names compare case-insensitively. On name collision (including
 * case-only duplicates), the first-discovered skill wins. Collision
 * diagnostics are recorded and logged.
 *
 * A SKILL.md whose frontmatter fails type or constraint validation is skipped
 * with a warning; other skills still load.
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

    private const string UPDATE_DIRECTORY = '.builtin-skill-updates';

    private const MAX_RECURSION_DEPTH = 20;

    /** @var list<SkillDefinition>|null */
    private ?array $cachedResult = null;

    /** @var list<array{winner: string, ignored: string, name: string}> */
    private array $collisions = [];

    /** @var array<string, SkillDefinition> lowercase name → winning definition */
    private array $skillsByName = [];

    /** @var list<string> Absolute skill directories registered by enabled extensions */
    private array $registeredSkillDirectories = [];

    /** @var array<string, array{name: string, origin: string}> In precedence order. */
    private array $sources = [];

    public function __construct(
        private readonly SkillsConfig $config,
        private readonly SettingsPathResolver $pathResolver,
        private readonly AppConfig $appConfig,
        private readonly MarkdownFrontmatterExtractor $extractor,
        private readonly AppResourceLocator $resources,
        private readonly Filesystem $filesystem,
        private readonly SkillFrontmatterValidator $frontmatterValidator,
        private readonly LockFactory $lockFactory,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** @return array<string, array{name: string, origin: string}> */
    public function sources(): array
    {
        $this->cachedResult = null;
        $this->discover(materializeBuiltins: false);
        $this->cachedResult = null;

        return $this->sources;
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
    public function discover(bool $materializeBuiltins = true): array
    {
        if (null !== $this->cachedResult) {
            return $this->cachedResult;
        }

        $cwd = $this->resolveCwd();
        $homeDir = $this->pathResolver->getHomeDir();

        // Materialize bundled skills into ~/.hatfield/skills before scanning.
        // Runs even when --no-skills suppresses auto-discovery so the home
        // copy stays current for later sessions.
        if ($materializeBuiltins) {
            $this->materializeBuiltinSkills($homeDir);
        }

        /** @var list<array{string, string}> $searchPaths */
        $searchPaths = [];

        // Step 1: CLI --skills-path entries (highest priority, always checked)
        foreach ($this->config->skillsPaths as $path) {
            $searchPaths[] = [$path, 'custom path'];
        }

        // Step 2: Auto-discovery paths (only when auto-discovery is enabled).
        // Patterns are highest→lowest Hatfield specificity; for each pattern
        // scan project then user so Hatfield-specific beats generic scope.
        if (!$this->config->noSkills) {
            foreach (self::AUTO_DISCOVERY_PATTERNS as $pattern) {
                foreach ([$cwd, $homeDir] as $baseDir) {
                    $path = \sprintf($pattern, $baseDir);
                    if (is_dir($path)) {
                        $searchPaths[] = [$path, 'local'];
                    }
                }
            }

            // Step 3: Extension-registered skill directories (lowest precedence)
            foreach ($this->registeredSkillDirectories as $directory) {
                $searchPaths[] = [$directory, 'package'];
            }
        }

        // Scan all search paths
        $this->sources = [];
        $skills = [];
        $this->collisions = [];
        $this->skillsByName = [];
        /** @var array<string, string> $seenNames lowercase name → first-seen skill dir */
        $seenNames = [];
        $seenRoots = [];

        foreach ($searchPaths as [$searchPath, $origin]) {
            $foundRoots = $this->scanForSkillRoots($searchPath, 0);

            foreach ($foundRoots as [$skillDir, $linked]) {
                if (isset($seenRoots[$skillDir])) {
                    continue;
                }
                $seenRoots[$skillDir] = true;
                $definition = $this->buildDefinition($skillDir);
                if (null === $definition) {
                    continue;
                }

                $name = $definition->name;
                $source = $origin;
                $builtin = $this->resources->getBuiltinSkillsPath().'/'.basename($skillDir).'/SKILL.md';
                if ('local' === $source && !$linked && $skillDir === rtrim($homeDir, '/').'/.hatfield/skills/'.basename($skillDir) && is_file($builtin)) {
                    $source = 'built-in';
                } elseif ('local' === $source && $linked) {
                    $source = 'linked path';
                }
                $this->sources[$definition->skillFile] = ['name' => $name, 'origin' => $source];

                $key = strtolower($name);
                if (isset($seenNames[$key])) {
                    $this->collisions[] = [
                        'winner' => $seenNames[$key],
                        'ignored' => $skillDir,
                        'name' => $name,
                    ];
                    if (null !== $this->logger) {
                        $this->logger->warning('Skill name collision: "{name}" already registered from "{winner}", ignoring "{ignored}"', [
                            'name' => $name,
                            'winner' => $seenNames[$key],
                            'ignored' => $skillDir,
                        ]);
                    }
                    continue;
                }

                $skills[] = $definition;
                $seenNames[$key] = $skillDir;
                $this->skillsByName[$key] = $definition;
            }
        }

        $this->cachedResult = $skills;

        return $skills;
    }

    /**
     * Look up the winning discovered skill by name, case-insensitively.
     */
    public function findByName(string $name): ?SkillDefinition
    {
        $this->discover();

        return $this->skillsByName[strtolower($name)] ?? null;
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
     * Serialize setup mutations with bundled skill materialization.
     *
     * @template T
     *
     * @param callable(): T $mutation
     *
     * @return T
     */
    public function mutateInstalledSkill(string $path, callable $mutation, bool $deleting = false): mixed
    {
        $root = rtrim($this->pathResolver->getHomeDir(), '/').'/.hatfield/skills';
        $directory = \dirname($path);
        $name = basename($directory);
        if (\dirname($directory) !== $root || !is_file($this->resources->getBuiltinSkillsPath().'/'.$name.'/SKILL.md')) {
            return $mutation();
        }

        return $this->withInstallLock($root, function () use ($root, $name, $directory, $mutation, $deleting): mixed {
            $receipt = $this->readInstallReceipt($root);
            $this->recoverInstall($root, $name, $directory, $root.'/'.self::UPDATE_DIRECTORY.'/'.$name, $receipt);
            $result = $mutation();
            if ($deleting) {
                // The copy can predate the receipt; remember a successful setup deletion.
                $receipt[$name] = null;
                $this->writeInstallReceipt($root, $receipt);
            }

            return $result;
        });
    }

    /**
     * Install bundled copies and update only fingerprint-proven pristine trees.
     * Modified, unrecognized legacy and deleted copies remain user-owned. A null
     * receipt opts a tree out of automatic replacement, including after deletion.
     */
    private function materializeBuiltinSkills(string $homeDir): void
    {
        $sourceRoot = $this->resources->getBuiltinSkillsPath();
        if (!is_dir($sourceRoot) || false === $entries = scandir($sourceRoot)) {
            return;
        }

        $destinationRoot = rtrim($homeDir, '/').'/.hatfield/skills';
        $this->withInstallLock($destinationRoot, function () use ($entries, $sourceRoot, $destinationRoot): void {
            $receipt = $this->readInstallReceipt($destinationRoot);
            foreach ($entries as $entry) {
                $source = $sourceRoot.'/'.$entry;
                if ('.' === $entry || '..' === $entry || !is_file($source.'/SKILL.md')) {
                    continue;
                }
                $target = $destinationRoot.'/'.$entry;
                // Stage on the same filesystem as the target. Discovery skips this state directory.
                $transaction = $destinationRoot.'/'.self::UPDATE_DIRECTORY.'/'.$entry;
                if (!$this->recoverInstall($destinationRoot, $entry, $target, $transaction, $receipt)) {
                    continue;
                }
                if (is_link($target) || (file_exists($target) && !is_dir($target))) {
                    continue;
                }
                $tracked = \array_key_exists($entry, $receipt);
                if ($tracked && (!is_dir($target) || null === $receipt[$entry])) {
                    continue;
                }
                $packaged = $this->treeFingerprint($source);
                if (null === $packaged) {
                    continue;
                }
                $installed = is_dir($target) ? $this->treeFingerprint($target) : null;
                if (is_dir($target) && null === $installed) {
                    continue;
                }
                if (is_dir($target) && !$tracked) {
                    // Without an old baseline only an exact match is known pristine.
                    $receipt[$entry] = $installed === $packaged ? $packaged : null;
                } elseif ($installed !== $packaged) {
                    if ($tracked && $installed !== $receipt[$entry]) {
                        continue;
                    }
                    // Until the journal exists, only the stage can have changed. Once it
                    // exists, keep the previous tree until the receipt is committed.
                    $stage = $transaction.'/stage';
                    $this->filesystem->remove($stage);
                    $this->filesystem->mirror($source, $stage);
                    if ($this->treeFingerprint($stage) !== $packaged) {
                        continue;
                    }
                    $this->filesystem->dumpFile($transaction.'/pending', $packaged);
                    if (is_dir($target)) {
                        $this->filesystem->rename($target, $transaction.'/previous');
                    }
                    $this->filesystem->rename($stage, $target);
                    $receipt[$entry] = $packaged;
                    $this->writeInstallReceipt($destinationRoot, $receipt);
                    $this->clearInstallTransaction($transaction);

                    continue;
                } else {
                    continue;
                }
                $this->writeInstallReceipt($destinationRoot, $receipt);
            }
        });
    }

    /** @param array<string, string|null> $receipt */
    private function recoverInstall(string $root, string $name, string $target, string $transaction, array &$receipt): bool
    {
        $pending = $transaction.'/pending';
        if (!is_file($pending)) {
            return true;
        }
        $next = $this->filesystem->readFile($pending);
        $previous = $transaction.'/previous';
        if (!\array_key_exists($name, $receipt) || (null !== $receipt[$name] && $receipt[$name] !== $next)) {
            if (!is_link($target) && is_dir($target) && $this->treeFingerprint($target) === $next) {
                // Promotion finished; only the atomic receipt write was interrupted.
                $receipt[$name] = $next;
                $this->writeInstallReceipt($root, $receipt);
            } elseif (!file_exists($target) && !is_link($target) && is_dir($previous)) {
                // The old tree was moved, but promotion did not finish. Restore it
                // before re-evaluating the currently packaged version.
                $this->filesystem->rename($previous, $target);
            } elseif (is_dir($previous)) {
                // Something else occupies the target; never overwrite it or discard the backup.
                return false;
            }
        }
        $this->clearInstallTransaction($transaction);

        return true;
    }

    private function clearInstallTransaction(string $transaction): void
    {
        // Remove the journal last: cleanup itself can be interrupted.
        $this->filesystem->remove([$transaction.'/stage', $transaction.'/previous']);
        $this->filesystem->remove($transaction.'/pending');
        $this->filesystem->remove($transaction);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withInstallLock(string $root, callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock('hatfield:builtin-skills:'.$root);
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, string|null> */
    private function readInstallReceipt(string $root): array
    {
        $path = $root.'/.builtin-installs.yaml';

        return is_file($path) ? Yaml::parse($this->filesystem->readFile($path)) : [];
    }

    /** @param array<string, string|null> $receipt */
    private function writeInstallReceipt(string $root, array $receipt): void
    {
        $this->filesystem->dumpFile($root.'/.builtin-installs.yaml', Yaml::dump($receipt));
    }

    private function treeFingerprint(string $directory): ?string
    {
        $entries = @scandir($directory);
        if (false === $entries) {
            return $this->fingerprintFailure($directory, error_get_last()['message'] ?? 'Unable to list directory');
        }
        $nodes = [];
        foreach ($entries as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $path = $directory.'/'.$name;
            if (is_link($path)) {
                return $this->fingerprintFailure($path, 'Symbolic links are not managed');
            }
            if (is_dir($path)) {
                $hash = $this->treeFingerprint($path);
                $kind = 'directory';
            } else {
                if (!is_file($path)) {
                    return $this->fingerprintFailure($path, 'Not a regular file');
                }
                $hash = @hash_file('sha256', $path);
                if (false === $hash) {
                    return $this->fingerprintFailure($path, error_get_last()['message'] ?? 'Unable to hash file');
                }
                $kind = 'file';
            }
            if (null === $hash) {
                return null;
            }
            $nodes[] = [$name, $kind, $hash];
        }

        return hash('sha256', serialize($nodes));
    }

    private function fingerprintFailure(string $path, string $error): null
    {
        $this->logger?->warning('skill.install.fingerprint_failed', ['component' => 'skill_discovery', 'event_type' => 'skill.install.fingerprint_failed', 'path' => $path, 'error' => $error]);

        return null;
    }

    /**
     * Recursively scan a directory for skill roots (directories containing SKILL.md).
     *
     * @return list<array{string, bool}> Canonical skill root and whether its discovery path crossed a symlink
     */
    private function scanForSkillRoots(string $dir, int $depth, bool $linked = false): array
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

        $linked = $linked || $realDir !== rtrim($dir, '/');

        // Check if this directory itself is a skill root
        $skillMdPath = $realDir.'/SKILL.md';
        if (is_file($skillMdPath)) {
            return [[$realDir, $linked]];
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

            if (self::UPDATE_DIRECTORY === $entry || !is_dir($childPath)) {
                continue;
            }

            $subResults = $this->scanForSkillRoots($childPath, $depth + 1, $linked);
            $results = array_merge($results, $subResults);
        }

        return $results;
    }

    /**
     * Build a SkillDefinition from a skill root directory.
     *
     * Reads and parses SKILL.md frontmatter to extract name, description,
     * and model invocation settings. Returns null (skip) when the frontmatter
     * values fail type or constraint validation.
     */
    private function buildDefinition(string $skillDir): ?SkillDefinition
    {
        $skillFile = $skillDir.'/SKILL.md';

        if (!is_file($skillFile)) {
            return null;
        }

        $content = @file_get_contents($skillFile);

        if (false === $content) {
            if (null !== $this->logger) {
                $this->logger->warning('Failed to read SKILL.md at {path}: {error}', ['path' => $skillFile, 'error' => error_get_last()['message'] ?? 'Unable to read skill']);
            }

            return null;
        }

        $frontmatter = $this->parseFrontmatter($content);

        try {
            $dto = $this->frontmatterValidator->validate($frontmatter);
        } catch (SkillFrontmatterValidationException $e) {
            if (null !== $this->logger) {
                $this->logger->warning('Skipping skill with invalid SKILL.md frontmatter', [
                    'component' => 'skills.discovery',
                    'event_type' => 'skill_frontmatter_invalid',
                    'path' => $skillFile,
                    'field' => $e->field,
                    'exception_class' => $e::class,
                ]);
            }

            return null;
        }

        return new SkillDefinition(
            name: $dto->name ?? basename($skillDir),
            description: $dto->description ?? '',
            skillFile: $skillFile,
            skillDirectory: $skillDir,
            modelInvocationEnabled: !$dto->disableModelInvocation,
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
