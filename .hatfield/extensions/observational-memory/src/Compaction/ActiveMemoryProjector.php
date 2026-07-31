<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Compaction;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmPaths;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;

/**
 * Shared durable-memory load/render for CompactRun and snapshot hooks.
 *
 * Returns trimmed rendered text, or empty string when there is nothing to project.
 * Throws on storage/render failures so callers can fail closed with privacy-safe cancel.
 */
final class ActiveMemoryProjector
{
    public static function renderActive(
        ExtensionApiInterface $api,
        OmSettings $settings,
        LoggerInterface $logger,
        string $runId,
    ): string {
        $paths = OmPaths::fromSettings($settings, $api->getCwd());
        $connection = OmDatabaseFactory::connectAndMigrate($paths->databasePath, $logger);
        $generationRepo = new MemoryGenerationRepository($connection);
        $observationRepo = new ObservationRepository($connection);

        $reflections = $generationRepo->listActiveReflections($runId);
        $observations = $observationRepo->listActiveCandidateObservations($runId);

        return trim(ActiveMemoryRenderer::render($reflections, $observations));
    }
}
