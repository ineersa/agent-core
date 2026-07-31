<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ActivityRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: om_current_activity is a single ephemeral per-run row with job-guarded clear,
 * stage transitions, cross-run isolation, and 5-minute staleness hiding.
 */
final class ActivityRepositoryTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-activity');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testUpsertTransitionGuardClearAndStaleHide(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new ActivityRepository($connection);

        $now = new \DateTimeImmutable('2026-07-29T12:00:00+00:00');
        $repo->upsert('run-a', 'job-1', 'observer', 2500, null, $now->format(\DateTimeInterface::ATOM));
        $row = $repo->findFresh('run-a', $now);
        $this->assertNotNull($row);
        $this->assertSame('observer', $row['stage']);
        $this->assertSame(2500, $row['current_tokens']);
        $this->assertNull($row['target_tokens']);

        $repo->upsert('run-a', 'job-1', 'reflector', 2500, null, $now->modify('+1 second')->format(\DateTimeInterface::ATOM));
        $row = $repo->findFresh('run-a', $now->modify('+1 second'));
        $this->assertNotNull($row);
        $this->assertSame('reflector', $row['stage']);

        $repo->upsert('run-a', 'job-2', 'dropper', 2500, 1000, $now->modify('+2 seconds')->format(\DateTimeInterface::ATOM));
        // Older job finally must not clear newer job row.
        $repo->clear('run-a', 'job-1');
        $row = $repo->findFresh('run-a', $now->modify('+2 seconds'));
        $this->assertNotNull($row);
        $this->assertSame('job-2', $row['job_id']);
        $this->assertSame('dropper', $row['stage']);
        $this->assertSame(1000, $row['target_tokens']);

        // Current job clear removes row.
        $repo->clear('run-a', 'job-2');
        $this->assertNull($repo->findFresh('run-a', $now->modify('+2 seconds')));

        // Cross-run isolation.
        $repo->upsert('run-a', 'job-a', 'observer', 10, null, $now->format(\DateTimeInterface::ATOM));
        $repo->upsert('run-b', 'job-b', 'reflector', 20, null, $now->format(\DateTimeInterface::ATOM));
        $this->assertSame('observer', $repo->findFresh('run-a', $now)['stage'] ?? null);
        $this->assertSame('reflector', $repo->findFresh('run-b', $now)['stage'] ?? null);
        $this->assertNull($repo->findFresh('run-missing', $now));

        // Stale after 5 minutes is hidden.
        $staleAt = $now->modify('-301 seconds');
        $repo->upsert('run-stale', 'job-s', 'observer', 99, null, $staleAt->format(\DateTimeInterface::ATOM));
        $this->assertNull($repo->findFresh('run-stale', $now));
        $freshAt = $now->modify('-299 seconds');
        $repo->upsert('run-fresh', 'job-f', 'observer', 88, null, $freshAt->format(\DateTimeInterface::ATOM));
        $this->assertNotNull($repo->findFresh('run-fresh', $now));
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
