<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Assets;

use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Psr\Log\LoggerInterface;

/**
 * Copies a missing project scout from the user definition after eligibility.
 * Add code_search without changing its model, skills, or instruction body.
 * Never invent a fallback or overwrite an existing project/user scout.
 */
final readonly class JbcontextAssetInstaller
{
    private const string CODE_SEARCH_TOOL = 'code_search';

    public function __construct(
        private JbcontextPaths $paths,
        private LoggerInterface $logger,
        private ?string $homeDir = null,
    ) {
    }

    public function install(): void
    {
        $this->installScoutFromUser();
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

        $content = JbcontextMarkdownFrontmatter::dump($frontmatter, $parsed['body']);

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
