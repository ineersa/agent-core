<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: compatible redelivery no-ops; mismatched digest conflicts; zero observations still cover.
 */
final class ObservationRepositoryIdempotencyTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('om-repo');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testZeroObservationCoverageIsIdempotentAndTracksWatermark(): void
    {
        $db = OmTestDatabase::connect($this->tmpDir.'/om.sqlite');
        (new OmSchemaMigrator($db->connection(), new NullLogger()))->migrate();
        $repo = new ObservationRepository($db->connection());

        $first = $repo->commitBoundaryCoverage(
            coverageKey: 'cov-1',
            runId: 'run-1',
            boundaryKey: 'b-1',
            sourceStartSeq: 1,
            sourceEndSeq: 10,
            sourceDigest: 'digest-a',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'provider/model',
            observations: [],
            coveredAt: '2026-07-23T00:00:00+00:00',
        );
        $this->assertSame('inserted', $first['status']);
        $this->assertSame(0, $first['observation_count']);

        $second = $repo->commitBoundaryCoverage(
            coverageKey: 'cov-1',
            runId: 'run-1',
            boundaryKey: 'b-1',
            sourceStartSeq: 1,
            sourceEndSeq: 10,
            sourceDigest: 'digest-a',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'provider/model',
            observations: [],
            coveredAt: '2026-07-23T00:00:01+00:00',
        );
        $this->assertSame('noop', $second['status']);
        $this->assertTrue($repo->hasCompatibleCoverage('cov-1', 'digest-a'));
        $this->assertSame(10, $repo->latestCoveredEndSeq('run-1', 'r1', 'o1'));
    }
}
