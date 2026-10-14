<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Thesis: castor check finalization may delete only the exact-run QA cache
 * roots (`.hatfield/cache-$qaRunId`, legacy `-$qaRunId-paraT*`, and
 * lane-scoped `-$qaRunId-<lane>-T*`) while preserving persistent caches,
 * unknown lanes and other QA run ids. No broad sweep.
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
        $ownedUnitWorker = $hatfield.'/cache-'.$qaRunId.'-unit-T1';
        $ownedTuiWorker = $hatfield.'/cache-'.$qaRunId.'-tui-T2';
        $ownedLlmWorker = $hatfield.'/cache-'.$qaRunId.'-llm-real-T3';
        $persistent = $hatfield.'/cache';
        $genericWorker = $hatfield.'/cache-paraT1';
        $otherQa = $hatfield.'/cache-qa-20260730-other-cafe';
        $prefixTrap = $hatfield.'/cache-'.$qaRunId.'-extra';
        $unknownLaneTrap = $hatfield.'/cache-'.$qaRunId.'-other-T1';

        foreach ([
            $ownedPrimary, $ownedWorker, $ownedWorker2, $ownedUnitWorker, $ownedTuiWorker, $ownedLlmWorker,
            $persistent, $genericWorker, $otherQa, $prefixTrap, $unknownLaneTrap,
        ] as $dir) {
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
                    'cache-'.$qaRunId.'-llm-real-T3',
                    'cache-'.$qaRunId.'-paraT1',
                    'cache-'.$qaRunId.'-paraT2',
                    'cache-'.$qaRunId.'-tui-T2',
                    'cache-'.$qaRunId.'-unit-T1',
                ],
                $removedBases,
            );

            $this->assertDirectoryDoesNotExist($ownedPrimary);
            $this->assertDirectoryDoesNotExist($ownedWorker);
            $this->assertDirectoryDoesNotExist($ownedWorker2);
            $this->assertDirectoryDoesNotExist($ownedUnitWorker);
            $this->assertDirectoryDoesNotExist($ownedTuiWorker);
            $this->assertDirectoryDoesNotExist($ownedLlmWorker);

            $this->assertDirectoryExists($persistent);
            $this->assertDirectoryExists($genericWorker);
            $this->assertDirectoryExists($otherQa);
            $this->assertDirectoryExists($prefixTrap);
            $this->assertDirectoryExists($unknownLaneTrap);
            $this->assertFileExists($persistent.'/marker.txt');
            $this->assertFileExists($otherQa.'/marker.txt');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testCompletedReportRetentionRecoversExactScopesButProtectsUnknownRuns(): void
    {
        require_once ProjectDir::get().'/.castor/helpers.php';
        $work = TestDirectoryIsolation::createProjectTempDir('qa-retention');
        $filesystem = new \Symfony\Component\Filesystem\Filesystem();
        try {
            foreach (range(1, 12) as $number) {
                $id = 'qa-20260101-000000-'.$number.'-deadbeef';
                $filesystem->dumpFile($work.'/var/reports/'.$id.'/.completed', json_encode([$id, $number], \JSON_THROW_ON_ERROR));
                $filesystem->mkdir([$work.'/var/tmp/'.$id, $work.'/.hatfield/cache-'.$id]);
            }
            $activeId = 'qa-20260101-000000-98-deadbeef';
            $active = $work.'/var/reports/'.$activeId;
            $invalid = $work.'/var/reports/qa-20260101-000000-99-deadbeef';
            $filesystem->mkdir([$active, $work.'/var/tmp/'.$activeId, $work.'/.hatfield/cache-'.$activeId]);
            $filesystem->dumpFile($invalid.'/.completed', 'invalid');
            \CastorTasks\prune_completed_qa_reports($work);
            \CastorTasks\prune_completed_qa_reports($work);
            $this->assertCount(11, glob($work.'/var/reports/qa-*/.completed') ?: [], 'Ten completed runs plus the protected invalid marker.');
            $this->assertDirectoryDoesNotExist($work.'/var/reports/qa-20260101-000000-1-deadbeef');
            $this->assertDirectoryExists($work.'/var/reports/qa-20260101-000000-12-deadbeef');
            $this->assertDirectoryExists($active);
            $this->assertDirectoryExists($invalid);
            $this->assertSame([$work.'/var/tmp/'.$activeId], glob($work.'/var/tmp/qa-*') ?: []);
            $this->assertSame([$work.'/.hatfield/cache-'.$activeId], glob($work.'/.hatfield/cache-*') ?: []);
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    #[TestWith(['exit'])]
    #[TestWith(['exception'])]
    public function testPreflightFailureFinalizesOwnedScopesWithoutDiscardingFailure(string $mode): void
    {
        require_once ProjectDir::get().'/.castor/shared.php';
        require_once ProjectDir::get().'/.castor/qa_tmux.php';
        $root = ProjectDir::get();
        $filesystem = new \Symfony\Component\Filesystem\Filesystem();
        $input = new InputStream();
        $process = new Process([
            \CastorTasks\castor_cli_executable(), '--castor-file='.$root.'/tests/CodingAgent/Fixtures/CastorPreflightFailure.php',
            'preflight-failure', '--mode='.$mode,
        ], $root, ['HATFIELD_KEEP_TMP' => '1', 'HATFIELD_SESSION_ID' => false]);
        $process->setTimeout(5);
        $process->setInput($input);
        $id = null;
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(static fn ($type, $output) => str_contains($output, 'ALLOCATED')));
            $this->assertTrue($process->isRunning());
            $this->assertSame(1, preg_match('/^ALLOCATED (.+)$/m', $process->getOutput(), $match));
            [$id, $os] = json_decode($match[1], true, flags: \JSON_THROW_ON_ERROR);
            $report = $root.'/var/reports/'.$id;
            $marker = $report.'/.os-temp';
            $allocation = (string) file_get_contents($marker);
            \CastorTasks\prune_completed_qa_reports($root);
            $this->assertDirectoryExists($os, 'An allocation without consumer completion is not deletion authority.');
            try {
                $filesystem->remove($marker);
                $filesystem->symlink($os.'/proof.txt', $marker);
                $this->assertNull(\CastorTasks\qa_os_temporary_directory($id, $root));
                $filesystem->remove($marker);
                $filesystem->dumpFile($marker, json_encode([$root, \dirname($os)], \JSON_THROW_ON_ERROR));
                $this->assertNull(\CastorTasks\qa_os_temporary_directory($id, $root), 'The external base is not the allocated scope.');
                $filesystem->dumpFile($marker, $allocation);
                $filesystem->rename($os, $os.'.saved');
                $filesystem->symlink($os.'.saved', $os);
                $this->assertNull(\CastorTasks\qa_os_temporary_directory($id, $root), 'Symlinked scopes are not deletion authority.');
            } finally {
                $filesystem->remove($marker);
                $filesystem->dumpFile($marker, $allocation);
                if (is_dir($os.'.saved')) {
                    $filesystem->remove($os);
                    $filesystem->rename($os.'.saved', $os);
                }
            }
            $input->close();
            $process->wait();
            $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertStringContainsString('controlled preflight failure', $process->getOutput().$process->getErrorOutput());
            $this->assertFileExists($report.'/.completed');
            $this->assertFileExists($report.'/preflight.log');
            $this->assertFileExists($report.'/diagnostics/'.basename($os).'/proof.txt');
            $this->assertDirectoryDoesNotExist($os);
            $this->assertFileDoesNotExist(substr($os, 0, -2));
            $this->assertDirectoryExists(\dirname($os), 'The external base is never owned.');
            $this->assertDirectoryDoesNotExist($root.'/var/tmp/'.$id);
            $this->assertDirectoryDoesNotExist($root.'/.hatfield/cache-'.$id);
            \CastorTasks\prune_completed_qa_reports($root);
            $this->assertFileExists($report.'/.completed', 'Failed runs participate in completed-report retention.');
        } finally {
            $input->close();
            $process->wait();
            if (null !== $id) {
                \CastorTasks\complete_qa_run($id, $root);
                TestDirectoryIsolation::removeDirectory($root.'/var/reports/'.$id);
            }
        }
    }
}
