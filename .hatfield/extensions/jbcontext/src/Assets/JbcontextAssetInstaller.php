<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Assets;

use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Psr\Log\LoggerInterface;

/**
 * Installs project skill and scout after eligibility.
 *
 * Skill: create when absent; reinstall when installed frontmatter version is
 * missing or differs from the bundled skill. Existing same-version files stay.
 *
 * Scout: when project scout is absent, copy the user-level scout and add
 * code_search + skill guidance while preserving model/thinking/tools/body.
 * Never invent a fallback scout. Never modify the user-level file. Existing
 * project scout files are left untouched.
 */
final readonly class JbcontextAssetInstaller
{
    private const string SKILL_NAME = 'jbcontext-semantic-search';
    private const string CODE_SEARCH_TOOL = 'code_search';

    private const string SCOUT_GUIDANCE = <<<'MD'

## jbcontext semantic search

When the relevant file or subsystem is unknown, use `code_search` for one focused semantic query before broad filesystem searches. Optionally narrow once with `path_filter`, then read promising files. Prefer IDE definition/references or direct reads once you know the symbol or path. Do not use semantic search for builds, tests, Git, or diff review.
MD;

    public function __construct(
        private JbcontextPaths $paths,
        private string $packageRoot,
        private LoggerInterface $logger,
        private ?string $homeDir = null,
    ) {
    }

    public function install(): void
    {
        $this->installSkill();
        $this->installScoutFromUser();
    }

    private function installSkill(): void
    {
        $sourcePath = $this->packageRoot.'/resources/skills/'.self::SKILL_NAME.'/SKILL.md';
        $destinationPath = $this->paths->skillDestinationDir.'/SKILL.md';
        $relativePath = '.hatfield/skills/'.self::SKILL_NAME.'/SKILL.md';

        if (!is_file($sourcePath)) {
            $this->logger->warning('jbcontext.assets.skill_source_missing', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.skill_source_missing',
            ]);

            return;
        }

        $bundled = (string) file_get_contents($sourcePath);
        $bundledVersion = JbcontextMarkdownFrontmatter::versionOf($bundled);
        if (null === $bundledVersion) {
            $this->logger->warning('jbcontext.assets.skill_bundled_version_missing', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.skill_bundled_version_missing',
            ]);

            return;
        }

        if (is_file($destinationPath)) {
            $installed = (string) @file_get_contents($destinationPath);
            $installedVersion = JbcontextMarkdownFrontmatter::versionOf($installed);
            if (!JbcontextMarkdownFrontmatter::isOutdated($installedVersion, $bundledVersion)) {
                return;
            }
        }

        if (!$this->ensureDirectory(\dirname($destinationPath), 'jbcontext.assets.skill_mkdir_failed')) {
            return;
        }

        if (false === @file_put_contents($destinationPath, $bundled)) {
            $this->logger->warning('jbcontext.assets.skill_write_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.skill_write_failed',
                'path' => $relativePath,
            ]);
        }
    }

    private function installScoutFromUser(): void
    {
        $destinationPath = $this->paths->scoutDestinationPath;
        if (is_file($destinationPath)) {
            return;
        }

        $userScout = $this->resolveUserScoutPath();
        if (null === $userScout || !is_file($userScout)) {
            $this->logger->warning('jbcontext.assets.scout_user_missing', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.scout_user_missing',
            ]);

            return;
        }

        $raw = (string) file_get_contents($userScout);
        $parsed = JbcontextMarkdownFrontmatter::parse($raw);
        if ([] === $parsed['frontmatter']) {
            $this->logger->warning('jbcontext.assets.scout_user_invalid', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.scout_user_invalid',
            ]);

            return;
        }

        $frontmatter = $parsed['frontmatter'];
        $tools = $this->stringList($frontmatter['tools'] ?? null);
        if (!\in_array(self::CODE_SEARCH_TOOL, $tools, true)) {
            $tools[] = self::CODE_SEARCH_TOOL;
        }
        $frontmatter['tools'] = $tools;

        $skills = $this->stringList($frontmatter['skills'] ?? null);
        if (!\in_array(self::SKILL_NAME, $skills, true)) {
            $skills[] = self::SKILL_NAME;
        }
        $frontmatter['skills'] = $skills;

        $body = rtrim($parsed['body'])."\n".self::SCOUT_GUIDANCE."\n";
        $content = JbcontextMarkdownFrontmatter::dump($frontmatter, $body);

        if (!$this->ensureDirectory(\dirname($destinationPath), 'jbcontext.assets.scout_mkdir_failed')) {
            return;
        }

        if (false === @file_put_contents($destinationPath, $content)) {
            $this->logger->warning('jbcontext.assets.scout_write_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.scout_write_failed',
                'path' => '.hatfield/agents/scout.md',
            ]);
        }
    }

    private function resolveUserScoutPath(): ?string
    {
        $home = $this->homeDir;
        if (null === $home) {
            $envHome = getenv('HOME');
            $home = false !== $envHome && '' !== $envHome ? $envHome : null;
        }
        if (null === $home || '' === trim($home)) {
            return null;
        }

        $candidates = [
            rtrim($home, '/').'/.hatfield/agents/scout.md',
            rtrim($home, '/').'/.agents/scout.md',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (\is_string($value)) {
            $parts = preg_split('/\s*,\s*/', trim($value));
            if (false === $parts) {
                return [];
            }

            return array_values(array_filter($parts, static fn (string $item): bool => '' !== $item));
        }
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private function ensureDirectory(string $dir, string $eventType): bool
    {
        if (is_dir($dir) || (@mkdir($dir, 0o777, true) && is_dir($dir))) {
            return true;
        }

        $this->logger->warning($eventType, [
            'component' => 'jbcontext',
            'event_type' => $eventType,
        ]);

        return false;
    }
}
