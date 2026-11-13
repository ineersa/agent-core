<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Assets;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Installs the packaged task-workflow skill into the project skill tree.
 *
 * Create when absent. Refresh the whole skill directory when the installed
 * SKILL.md frontmatter version is missing or differs from the bundled skill.
 * Same-version installs stay untouched so local edits survive.
 */
final readonly class TaskWorkflowSkillInstaller
{
    public const string SKILL_NAME = 'task-workflow';

    public function __construct(
        private string $projectRoot,
        private string $packageRoot,
        private LoggerInterface $logger,
    ) {
    }

    public function install(bool $existingManagedOnly = false): void
    {
        $sourceDir = $this->packageRoot.'/skills/'.self::SKILL_NAME;
        $sourceSkill = $sourceDir.'/SKILL.md';
        $destinationDir = rtrim($this->projectRoot, '/').'/.hatfield/skills/'.self::SKILL_NAME;
        $destinationSkill = $destinationDir.'/SKILL.md';
        $relativePath = '.hatfield/skills/'.self::SKILL_NAME;

        // Auto-discovered worktree copies outrank registered skills. Only refresh
        // identifiable managed copies, never create one or follow it outside the tree.
        if ($existingManagedOnly) {
            if (!is_file($destinationSkill) || is_link($destinationDir) || is_link($destinationSkill)) {
                return;
            }
            $root = realpath($this->projectRoot);
            $destination = realpath($destinationDir);
            if (false === $root || false === $destination || !Path::isBasePath($root, $destination)) {
                return;
            }
        }

        if (!is_file($sourceSkill)) {
            $this->logger->warning('task_workflow.assets.skill_source_missing', [
                'component' => 'task_workflow',
                'event_type' => 'task_workflow.assets.skill_source_missing',
                'path' => $relativePath,
            ]);

            return;
        }

        $bundled = (string) file_get_contents($sourceSkill);
        $bundledVersion = TaskWorkflowMarkdownFrontmatter::versionOf($bundled);
        if (null === $bundledVersion) {
            $this->logger->warning('task_workflow.assets.skill_bundled_version_missing', [
                'component' => 'task_workflow',
                'event_type' => 'task_workflow.assets.skill_bundled_version_missing',
                'path' => $relativePath,
            ]);

            return;
        }

        if (is_file($destinationSkill)) {
            $installed = (string) @file_get_contents($destinationSkill);
            try {
                $frontmatter = TaskWorkflowMarkdownFrontmatter::parse($installed)['frontmatter'];
                $installedVersion = TaskWorkflowMarkdownFrontmatter::versionOf($installed);
            } catch (\Symfony\Component\Yaml\Exception\ParseException $exception) {
                $this->logger->warning('task_workflow.assets.skill_installed_version_invalid', [
                    'component' => 'task_workflow',
                    'event_type' => 'task_workflow.assets.skill_installed_version_invalid',
                    'path' => $relativePath,
                    'exception_class' => $exception::class,
                ]);
                $installedVersion = null;
                $frontmatter = [];
            }
            if ($existingManagedOnly && (self::SKILL_NAME !== ($frontmatter['name'] ?? null) || null === $installedVersion)) {
                return;
            }
            if (!TaskWorkflowMarkdownFrontmatter::isOutdated($installedVersion, $bundledVersion)) {
                return;
            }
        }

        $filesystem = new Filesystem();
        try {
            $filesystem->remove($destinationDir);
            $iterator = new \CallbackFilterIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::SELF_FIRST,
                ),
                static fn (\SplFileInfo $file): bool => $file->getPathname() !== $sourceSkill,
            );
            $filesystem->mirror($sourceDir, $destinationDir, $iterator);
            // Keep the installed guide self-contained for project- and user-scope packages.
            $filesystem->copy($this->packageRoot.'/README.md', $destinationDir.'/README.md', true);
            $filesystem->mirror($this->packageRoot.'/templates/issue', $destinationDir.'/templates/issue');
            // Publish the version last so a partial copy is retried on next registration.
            $filesystem->dumpFile($destinationSkill, $bundled);
        } catch (\Symfony\Component\Filesystem\Exception\IOExceptionInterface $exception) {
            $this->logger->warning('task_workflow.assets.skill_write_failed', [
                'component' => 'task_workflow',
                'event_type' => 'task_workflow.assets.skill_write_failed',
                'path' => $relativePath,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
