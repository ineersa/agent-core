<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Assets;

use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Psr\Log\LoggerInterface;

/**
 * Installs marker-managed project skill and scout after eligibility.
 *
 * Never overwrites user-owned destinations. Collisions are logged and skipped.
 */
final readonly class JbcontextAssetInstaller
{
    public function __construct(
        private JbcontextPaths $paths,
        private string $packageRoot,
        private LoggerInterface $logger,
    ) {
    }

    public function install(): void
    {
        $this->installSkill();
        $this->installScout();
    }

    private function installSkill(): void
    {
        $sourceDir = $this->packageRoot.'/resources/skills/jbcontext-semantic-search';
        $sourceSkill = $sourceDir.'/SKILL.md';
        $destDir = $this->paths->skillDestinationDir;
        $destSkill = $destDir.'/SKILL.md';

        if (!is_file($sourceSkill)) {
            $this->logger->warning('jbcontext.assets.skill_source_missing', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.skill_source_missing',
            ]);

            return;
        }

        if (is_file($destSkill)) {
            $existing = (string) @file_get_contents($destSkill);
            if (!JbcontextManagedMarker::isManaged($existing)) {
                $this->logger->warning('jbcontext.assets.skill_collision', [
                    'component' => 'jbcontext',
                    'event_type' => 'jbcontext.assets.skill_collision',
                    'path' => '.hatfield/skills/jbcontext-semantic-search/SKILL.md',
                ]);

                return;
            }
        }

        if (!is_dir($destDir) && !@mkdir($destDir, 0o777, true) && !is_dir($destDir)) {
            $this->logger->warning('jbcontext.assets.skill_mkdir_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.skill_mkdir_failed',
            ]);

            return;
        }

        $body = (string) file_get_contents($sourceSkill);
        if (!JbcontextManagedMarker::isManaged($body)) {
            $body = $this->injectMarkerAfterFrontmatter($body);
        }

        if (false === @file_put_contents($destSkill, $body)) {
            $this->logger->warning('jbcontext.assets.skill_write_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.skill_write_failed',
            ]);
        }
    }

    private function installScout(): void
    {
        $source = $this->packageRoot.'/resources/agents/scout.md';
        $dest = $this->paths->scoutDestinationPath;

        if (!is_file($source)) {
            $this->logger->warning('jbcontext.assets.scout_source_missing', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.scout_source_missing',
            ]);

            return;
        }

        if (is_file($dest)) {
            $existing = (string) @file_get_contents($dest);
            if (!JbcontextManagedMarker::isManaged($existing)) {
                $this->logger->warning('jbcontext.assets.scout_collision', [
                    'component' => 'jbcontext',
                    'event_type' => 'jbcontext.assets.scout_collision',
                    'path' => '.hatfield/agents/scout.md',
                ]);

                return;
            }
        }

        $destDir = \dirname($dest);
        if (!is_dir($destDir) && !@mkdir($destDir, 0o777, true) && !is_dir($destDir)) {
            $this->logger->warning('jbcontext.assets.scout_mkdir_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.scout_mkdir_failed',
            ]);

            return;
        }

        $body = (string) file_get_contents($source);
        if (!JbcontextManagedMarker::isManaged($body)) {
            $body = $this->injectMarkerAfterFrontmatter($body);
        }

        if (false === @file_put_contents($dest, $body)) {
            $this->logger->warning('jbcontext.assets.scout_write_failed', [
                'component' => 'jbcontext',
                'event_type' => 'jbcontext.assets.scout_write_failed',
            ]);
        }
    }

    private function injectMarkerAfterFrontmatter(string $body): string
    {
        if (str_starts_with($body, "---\n")) {
            $end = strpos($body, "\n---\n", 4);
            if (false !== $end) {
                $insertAt = $end + \strlen("\n---\n");

                return substr($body, 0, $insertAt)
                    .JbcontextManagedMarker::skillFrontmatterMarkerLine()."\n"
                    .substr($body, $insertAt);
            }
        }

        return JbcontextManagedMarker::skillFrontmatterMarkerLine()."\n".$body;
    }
}
