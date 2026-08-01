<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: castor check finalization may delete only the exact-run QA cache
 * roots (`.hatfield/cache-$qaRunId` and `.hatfield/cache-$qaRunId-paraT*`)
 * while preserving persistent `.hatfield/cache`, generic `cache-paraT*`, and
 * other QA run ids. No broad sweep.
 */
final class ExactQaRunCacheCleanupTest extends TestCase
{
    public function testCleanupRemovesExactOwnedRootsAndPreservesNeighbors(): void
    {
        $root = ProjectDir::get();
        $helpersPhp = $root.'/.castor/helpers.php';
        $this->assertFileExists($helpersPhp);
        require_once $helpersPhp;
        $this->assertTrue(
            \function_exists('CastorTasks\cleanup_exact_qa_run_cache_roots'),
            'cleanup_exact_qa_run_cache_roots must load from .castor/helpers.php',
        );

        $work = TestDirectoryIsolation::createProjectTempDir('exact-qa-cache-cleanup');
        $hatfield = $work.'/.hatfield';
        mkdir($hatfield, 0755, true);

        $qaRunId = 'qa-20260731-123456-9999-deadbeef';
        $ownedPrimary = $hatfield.'/cache-'.$qaRunId;
        $ownedWorker = $hatfield.'/cache-'.$qaRunId.'-paraT1';
        $ownedWorker2 = $hatfield.'/cache-'.$qaRunId.'-paraT2';
        $persistent = $hatfield.'/cache';
        $genericWorker = $hatfield.'/cache-paraT1';
        $otherQa = $hatfield.'/cache-qa-20260730-other-cafe';
        $prefixTrap = $hatfield.'/cache-'.$qaRunId.'-extra';

        foreach ([$ownedPrimary, $ownedWorker, $ownedWorker2, $persistent, $genericWorker, $otherQa, $prefixTrap] as $dir) {
            mkdir($dir, 0755, true);
            file_put_contents($dir.'/marker.txt', basename($dir));
        }

        try {
            $removed = \CastorTasks\cleanup_exact_qa_run_cache_roots($qaRunId, $work);
            $removedBases = array_map(static fn (string $path): string => basename($path), $removed);
            sort($removedBases);

            $this->assertSame(
                [
                    'cache-'.$qaRunId,
                    'cache-'.$qaRunId.'-paraT1',
                    'cache-'.$qaRunId.'-paraT2',
                ],
                $removedBases,
            );

            $this->assertDirectoryDoesNotExist($ownedPrimary);
            $this->assertDirectoryDoesNotExist($ownedWorker);
            $this->assertDirectoryDoesNotExist($ownedWorker2);

            $this->assertDirectoryExists($persistent);
            $this->assertDirectoryExists($genericWorker);
            $this->assertDirectoryExists($otherQa);
            $this->assertDirectoryExists($prefixTrap);
            $this->assertFileExists($persistent.'/marker.txt');
            $this->assertFileExists($otherQa.'/marker.txt');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }
}
