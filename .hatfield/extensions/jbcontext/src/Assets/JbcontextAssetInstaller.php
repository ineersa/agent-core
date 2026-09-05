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
        $this->installManagedFile(
            sourcePath: $this->packageRoot.'/resources/skills/jbcontext-semantic-search/SKILL.md',
            destinationPath: $this->paths->skillDestinationDir.'/SKILL.md',
            relativePath: '.hatfield/skills/jbcontext-semantic-search/SKILL.md',
            eventPrefix: 'jbcontext.assets.skill',
        );
        $this->installManagedFile(
            sourcePath: $this->packageRoot.'/resources/agents/scout.md',
            destinationPath: $this->paths->scoutDestinationPath,
            relativePath: '.hatfield/agents/scout.md',
            eventPrefix: 'jbcontext.assets.scout',
        );
    }

    private function installManagedFile(
        string $sourcePath,
        string $destinationPath,
        string $relativePath,
        string $eventPrefix,
    ): void {
        if (!is_file($sourcePath)) {
            $this->logger->warning($eventPrefix.'_source_missing', [
                'component' => 'jbcontext',
                'event_type' => $eventPrefix.'_source_missing',
            ]);

            return;
        }

        if (is_file($destinationPath)) {
            $existing = (string) @file_get_contents($destinationPath);
            if (!JbcontextManagedMarker::isManaged($existing)) {
                $this->logger->warning($eventPrefix.'_collision', [
                    'component' => 'jbcontext',
                    'event_type' => $eventPrefix.'_collision',
                    'path' => $relativePath,
                ]);

                return;
            }
        }

        $destinationDir = \dirname($destinationPath);
        if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0o777, true) && !is_dir($destinationDir)) {
            $this->logger->warning($eventPrefix.'_mkdir_failed', [
                'component' => 'jbcontext',
                'event_type' => $eventPrefix.'_mkdir_failed',
            ]);

            return;
        }

        $body = (string) file_get_contents($sourcePath);
        if (!JbcontextManagedMarker::isManaged($body)) {
            $body = $this->injectMarkerAfterFrontmatter($body);
        }

        if (false === @file_put_contents($destinationPath, $body)) {
            $this->logger->warning($eventPrefix.'_write_failed', [
                'component' => 'jbcontext',
                'event_type' => $eventPrefix.'_write_failed',
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
                    .JbcontextManagedMarker::markerLine()."\n"
                    .substr($body, $insertAt);
            }
        }

        return JbcontextManagedMarker::markerLine()."\n".$body;
    }
}
