<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;

/**
 * Thesis: chunk/part coverage is idempotent; incomplete parts do not advance; complete intervals walk from 1; no MAX shortcut.
 * Active candidates use generation required_end_seq watermark, not created_at second precision.
 */
final class ObservationRepositoryIdempotencyTest extends IsolatedKernelTestCase
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
        $connection = $this->omDatabaseFactory()->connectAndMigrate($this->tmpDir.'/om.sqlite');
        $repo = new ObservationRepository($connection);

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
        $connection = $this->omDatabaseFactory()->connectAndMigrate($this->tmpDir.'/om-parts.sqlite');
        $repo = new ObservationRepository($connection);

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

    public function testActiveCandidatesUseSourceWatermarkNotCreatedAtSecond(): void
    {
        $connection = $this->omDatabaseFactory()->connectAndMigrate($this->tmpDir.'/om-active.sqlite');
        $repo = new ObservationRepository($connection);
        $genRepo = new \Ineersa\HatfieldExt\ObservationalMemory\Storage\MemoryGenerationRepository($connection);
        $sameSecond = '2026-07-26T12:00:00+00:00';

        $retainedId = hash('sha256', 'retained');
        $droppedId = hash('sha256', 'dropped');
        $laterId = hash('sha256', 'later');

        foreach ([
            [$retainedId, 1, 2, 'kept observation'],
            [$droppedId, 3, 4, 'dropped same-second observation'],
            [$laterId, 5, 6, 'later source observation'],
        ] as [$id, $start, $end, $content]) {
            $connection->insert('om_observation', [
                'observation_id' => $id,
                'run_id' => 'run-a',
                'boundary_key' => 'b',
                'source_start_seq' => $start,
                'source_end_seq' => $end,
                'source_refs_json' => '[]',
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'relevance' => 'medium',
                'timestamp' => '2026-07-26 12:00',
                'token_count' => 4,
                'observer_model' => 'm',
                'observer_schema_version' => '1',
                'created_at' => $sameSecond,
            ]);
        }

        $generationId = hash('sha256', 'gen-active');
        $connection->insert('om_memory_generation', [
            'generation_id' => $generationId,
            'run_id' => 'run-a',
            'trigger_kind' => 'threshold',
            'status' => 'succeeded',
            'observation_set_hash' => hash('sha256', 'set'),
            'reflector_model' => 'm',
            'reflector_schema_version' => '1',
            'threshold_idempotency_key' => $generationId,
            'required_start_seq' => 1,
            'required_end_seq' => 4, // watermark: retained+dropped covered; later seq 6 is after
            'compaction_request_id' => null,
            'request_fingerprint' => null,
            'failure_code' => null,
            'created_at' => $sameSecond,
            'completed_at' => $sameSecond,
        ]);
        $connection->insert('om_generation_retained_observation', [
            'generation_id' => $generationId,
            'observation_id' => $retainedId,
            'position' => 0,
        ]);
        $connection->insert('om_active_generation', [
            'run_id' => 'run-a',
            'generation_id' => $generationId,
        ]);

        $candidate = $repo->activeCandidateSet('run-a');
        $this->assertContains($retainedId, $candidate['observation_ids']);
        $this->assertContains($laterId, $candidate['observation_ids'], 'later source_end_seq must be included even with same created_at');
        $this->assertNotContains($droppedId, $candidate['observation_ids'], 'dropped same-second observation must stay excluded');
        $this->assertSame(6, $candidate['max_source_end_seq']);
        $this->assertSame(
            \Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity::observationSetHash('run-a', [$laterId, $retainedId]),
            $candidate['observation_set_hash'],
        );
        // ensure generation repo still reachable for kernel wiring
        $this->assertSame($generationId, $genRepo->activeGenerationId('run-a'));
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
