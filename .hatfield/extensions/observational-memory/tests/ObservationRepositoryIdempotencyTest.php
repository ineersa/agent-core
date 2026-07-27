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
 * Thesis: chunk/part coverage is idempotent; incomplete parts do not advance; complete intervals walk from 1; no MAX shortcut.
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

    public function testZeroObservationPartCoverageIsIdempotentAndTracksWatermark(): void
    {
        $db = OmTestDatabase::connect($this->tmpDir.'/om.sqlite');
        (new OmSchemaMigrator($db->connection(), new NullLogger()))->migrate();
        $repo = new ObservationRepository($db->connection());

        $first = $repo->commitChunkPartCoverage(
            coverageKey: 'cov-1',
            runId: 'run-1',
            boundaryKey: 'b-1',
            sourceStartSeq: 1,
            sourceEndSeq: 10,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-a',
            partDigest: 'part-a',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'provider/model',
            observations: [],
            coveredAt: '2026-07-23T00:00:00+00:00',
        );
        $this->assertSame('inserted', $first['status']);
        $this->assertSame(0, $first['observation_count']);

        $second = $repo->commitChunkPartCoverage(
            coverageKey: 'cov-1',
            runId: 'run-1',
            boundaryKey: 'b-1',
            sourceStartSeq: 1,
            sourceEndSeq: 10,
            chunkKey: 'chunk-1',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'digest-a',
            partDigest: 'part-a',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'provider/model',
            observations: [],
            coveredAt: '2026-07-23T00:00:01+00:00',
        );
        $this->assertSame('noop', $second['status']);
        $this->assertTrue($repo->hasCompatibleCoverage('cov-1', 'digest-a', 'part-a'));
        $this->assertSame(10, $repo->contiguousCoveredEndSeq('run-1', 'r1', 'o1'));
    }

    public function testIncompletePartGroupDoesNotAdvanceAndGapStops(): void
    {
        $db = OmTestDatabase::connect($this->tmpDir.'/om-parts.sqlite');
        (new OmSchemaMigrator($db->connection(), new NullLogger()))->migrate();
        $repo = new ObservationRepository($db->connection());

        // Incomplete multi-part chunk for seq 1.
        $repo->commitChunkPartCoverage(
            coverageKey: 'p1',
            runId: 'run-1',
            boundaryKey: 'b',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-big',
            partIndex: 1,
            partCount: 2,
            sourceDigest: 'src',
            partDigest: 'd1',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: '2026-07-26T00:00:00+00:00',
        );
        $this->assertNull($repo->contiguousCoveredEndSeq('run-1', 'r1', 'o1'));

        // Complete second part -> seq 1 covered.
        $repo->commitChunkPartCoverage(
            coverageKey: 'p2',
            runId: 'run-1',
            boundaryKey: 'b',
            sourceStartSeq: 1,
            sourceEndSeq: 1,
            chunkKey: 'chunk-big',
            partIndex: 2,
            partCount: 2,
            sourceDigest: 'src',
            partDigest: 'd2',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: '2026-07-26T00:00:01+00:00',
        );
        $this->assertSame(1, $repo->contiguousCoveredEndSeq('run-1', 'r1', 'o1'));

        // Island at 5..6 must not advance past gap.
        $repo->commitChunkPartCoverage(
            coverageKey: 'island',
            runId: 'run-1',
            boundaryKey: 'b',
            sourceStartSeq: 5,
            sourceEndSeq: 6,
            chunkKey: 'chunk-island',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'src2',
            partDigest: 'd3',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: '2026-07-26T00:00:02+00:00',
        );
        $this->assertSame(1, $repo->contiguousCoveredEndSeq('run-1', 'r1', 'o1'));
    }
}
