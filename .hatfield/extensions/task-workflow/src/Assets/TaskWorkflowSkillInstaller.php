<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Assets;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

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

    public function install(): void
    {
        $sourceDir = $this->packageRoot.'/skills/'.self::SKILL_NAME;
        $sourceSkill = $sourceDir.'/SKILL.md';
        $destinationDir = rtrim($this->projectRoot, '/').'/.hatfield/skills/'.self::SKILL_NAME;
        $destinationSkill = $destinationDir.'/SKILL.md';
        $relativePath = '.hatfield/skills/'.self::SKILL_NAME;

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
                $installedVersion = TaskWorkflowMarkdownFrontmatter::versionOf($installed);
            } catch (\Symfony\Component\Yaml\Exception\ParseException $exception) {
                $this->logger->warning('task_workflow.assets.skill_installed_version_invalid', [
                    'component' => 'task_workflow',
                    'event_type' => 'task_workflow.assets.skill_installed_version_invalid',
                    'path' => $relativePath,
                    'exception_class' => $exception::class,
                ]);
                $installedVersion = null;
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
