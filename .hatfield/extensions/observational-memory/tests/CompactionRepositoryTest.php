<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: request identity is immutable; multiple reflections per request work;
 * contiguous coverage ignores later islands when earlier gaps exist.
 */
final class CompactionRepositoryTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-repo');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testMultipleReflectionsAndContiguousCoverageGap(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = OmDatabaseFactory::connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $obs = new ObservationRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        // Island at 5..6 without covering 1..4 must not report contiguous end 6.
        $obs->commitBoundaryCoverage(
            coverageKey: 'c-island',
            runId: 'run-g',
            boundaryKey: 'b-island',
            sourceStartSeq: 5,
            sourceEndSeq: 6,
            sourceDigest: 'd-island',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: $now,
        );
        $this->assertNull($obs->contiguousCoveredEndSeq('run-g', 'r1', 'o1'));

        $obs->commitBoundaryCoverage(
            coverageKey: 'c-head',
            runId: 'run-g',
            boundaryKey: 'b-head',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            sourceDigest: 'd-head',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: $now,
        );
        $this->assertSame(2, $obs->contiguousCoveredEndSeq('run-g', 'r1', 'o1'));

        $repo->ensureRequest('req-m', 'run-g', 1, 6, 6, 'fp-m', $now);
        $repo->commitSuccess(
            requestId: 'req-m',
            resultId: 'res-m',
            runId: 'run-g',
            requiredStartSeq: 1,
            requiredEndSeq: 6,
            requiredWatermark: 6,
            requestFingerprint: 'fp-m',
            observationSetHash: 'set-m',
            replacementText: 'summary',
            reflectorModel: 'p/m',
            reflectorSchemaVersion: 'rv1',
            reflections: [
                [
                    'reflection_id' => 'ref-1',
                    'content' => 'one',
                    'supporting_observation_ids_json' => '[]',
                    'compression_level' => '0',
                    'token_count' => 1,
                ],
                [
                    'reflection_id' => 'ref-2',
                    'content' => 'two',
                    'supporting_observation_ids_json' => '[]',
                    'compression_level' => '0',
                    'token_count' => 1,
                ],
            ],
            now: $now,
        );

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM om_reflection WHERE compaction_request_id = ?',
            ['req-m'],
        );
        $this->assertSame(2, $count);

        $this->expectException(OmConflictException::class);
        $repo->ensureRequest('req-m', 'run-g', 1, 7, 7, 'fp-other', $now);
    }
}
