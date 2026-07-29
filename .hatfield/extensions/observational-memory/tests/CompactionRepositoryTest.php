<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmConflictException;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: request fingerprint is immutable identity; observation_set_hash freezes later;
 * multiple reflections per request work; contiguous coverage ignores later islands.
 */
final class CompactionRepositoryTest extends IsolatedKernelTestCase
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
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $obs = new ObservationRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $obs->commitChunkPartCoverage(
            coverageKey: 'c-island',
            runId: 'run-g',
            boundaryKey: 'b-island',
            sourceStartSeq: 5,
            sourceEndSeq: 6,
            chunkKey: 'chunk-island',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd-island',
            partDigest: 'd-island',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: $now,
        );
        $this->assertNull($obs->contiguousCoveredEndSeq('run-g', 'r1', 'o1'));

        $obs->commitChunkPartCoverage(
            coverageKey: 'c-head',
            runId: 'run-g',
            boundaryKey: 'b-head',
            sourceStartSeq: 1,
            sourceEndSeq: 2,
            chunkKey: 'chunk-head',
            partIndex: 1,
            partCount: 1,
            sourceDigest: 'd-head',
            partDigest: 'd-head',
            rendererVersion: 'r1',
            observerSchemaVersion: 'o1',
            observerModel: 'p/m',
            observations: [],
            coveredAt: $now,
        );
        $this->assertSame(2, $obs->contiguousCoveredEndSeq('run-g', 'r1', 'o1'));

        $repo->ensureRequest('req-m', 'run-g', 1, 6, 6, 'fp-m', $now);
        $repo->markRunning('req-m', 'fp-m', $now);
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
            now: $now,
        );

        $frozen = $connection->fetchOne(
            'SELECT observation_set_hash FROM om_compaction_request WHERE request_id = ?',
            ['req-m'],
        );
        $this->assertSame('set-m', $frozen);

        $this->expectException(OmConflictException::class);
        $repo->ensureRequest('req-m', 'run-g', 1, 7, 7, 'fp-other', $now);
    }

    public function testCommitFailureDoesNotOverwriteTimedOutRequest(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $repo->ensureRequest('req-to', 'run-to', 1, 4, 4, 'fp-to', $now);
        $repo->markTimedOut('req-to', $now);

        try {
            $repo->commitFailure(
                requestId: 'req-to',
                resultId: 'res-to-fail',
                runId: 'run-to',
                requiredStartSeq: 1,
                requiredEndSeq: 4,
                requiredWatermark: 4,
                requestFingerprint: 'fp-to',
                failureCode: 'tool_not_called',
                now: $now,
                failureMetadata: ['exception_class' => 'RuntimeException'],
            );
            $this->fail('commitFailure against timed_out request must conflict');
        } catch (OmConflictException) {
            // expected
        }

        $status = $repo->getRequestStatus('req-to');
        $this->assertNotNull($status);
        $this->assertSame(CompactionRepository::STATUS_TIMED_OUT, $status['status']);
        $this->assertNull($repo->getResult('req-to'));
        $this->assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM om_compaction_result WHERE request_id = ?',
                ['req-to'],
            ),
        );
    }

    public function testMarkTimedOutFailsAssociatedRunningGenerationAtomically(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $repo->ensureRequest('req-gen-to', 'run-gen-to', 1, 5, 5, 'fp-gen-to', $now);
        $connection->executeStatement(
            'UPDATE om_compaction_request SET status = ?, updated_at = ? WHERE request_id = ?',
            [CompactionRepository::STATUS_RUNNING, $now, 'req-gen-to'],
        );
        $generationId = hash('sha256', 'gen-timeout-running');
        $connection->insert('om_memory_generation', [
            'generation_id' => $generationId,
            'run_id' => 'run-gen-to',
            'trigger_kind' => 'compaction',
            'status' => 'running',
            'observation_set_hash' => hash('sha256', 'set-gen-to'),
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'threshold_idempotency_key' => null,
            'required_start_seq' => 1,
            'required_end_seq' => 5,
            'compaction_request_id' => 'req-gen-to',
            'request_fingerprint' => 'fp-gen-to',
            'failure_code' => null,
            'created_at' => $now,
            'completed_at' => null,
        ]);

        $repo->markTimedOut('req-gen-to', $now);

        $status = $repo->getRequestStatus('req-gen-to');
        $this->assertNotNull($status);
        $this->assertSame(CompactionRepository::STATUS_TIMED_OUT, $status['status']);
        $this->assertNull($repo->getResult('req-gen-to'));

        $generation = $connection->fetchAssociative(
            'SELECT status, failure_code, completed_at FROM om_memory_generation WHERE generation_id = ?',
            [$generationId],
        );
        $this->assertIsArray($generation);
        $this->assertSame('failed', $generation['status']);
        $this->assertSame('timed_out', $generation['failure_code']);
        $this->assertSame($now, $generation['completed_at']);
        $this->assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM om_active_generation WHERE run_id = ?', ['run-gen-to']),
            'timeout must not promote active generation',
        );
    }

    public function testMarkTimedOutDoesNotCorruptSucceededGenerationWhenCasLoses(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $repo->ensureRequest('req-success-wins', 'run-success-wins', 1, 3, 3, 'fp-success-wins', $now);
        $connection->executeStatement(
            'UPDATE om_compaction_request SET status = ?, updated_at = ?, completed_at = ? WHERE request_id = ?',
            [CompactionRepository::STATUS_SUCCEEDED, $now, $now, 'req-success-wins'],
        );
        $generationId = hash('sha256', 'gen-success-wins');
        $connection->insert('om_memory_generation', [
            'generation_id' => $generationId,
            'run_id' => 'run-success-wins',
            'trigger_kind' => 'compaction',
            'status' => 'succeeded',
            'observation_set_hash' => hash('sha256', 'set-success-wins'),
            'reflector_model' => 'llama_cpp_test/test',
            'reflector_schema_version' => '1',
            'threshold_idempotency_key' => null,
            'required_start_seq' => 1,
            'required_end_seq' => 3,
            'compaction_request_id' => 'req-success-wins',
            'request_fingerprint' => 'fp-success-wins',
            'failure_code' => null,
            'created_at' => $now,
            'completed_at' => $now,
        ]);

        $repo->markTimedOut('req-success-wins', $now);

        $status = $repo->getRequestStatus('req-success-wins');
        $this->assertNotNull($status);
        $this->assertSame(CompactionRepository::STATUS_SUCCEEDED, $status['status']);
        $generation = $connection->fetchAssociative(
            'SELECT status, failure_code FROM om_memory_generation WHERE generation_id = ?',
            [$generationId],
        );
        $this->assertIsArray($generation);
        $this->assertSame('succeeded', $generation['status']);
        $this->assertNull($generation['failure_code']);
    }

    public function testMarkTimedOutQueuedRequestWithoutGenerationIsSafe(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $repo->ensureRequest('req-queued-only', 'run-queued-only', 1, 2, 2, 'fp-queued-only', $now);
        $repo->markTimedOut('req-queued-only', $now);

        $status = $repo->getRequestStatus('req-queued-only');
        $this->assertNotNull($status);
        $this->assertSame(CompactionRepository::STATUS_TIMED_OUT, $status['status']);
        $this->assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM om_memory_generation WHERE compaction_request_id = ?',
                ['req-queued-only'],
            ),
        );
        $this->assertNull($repo->getResult('req-queued-only'));
    }

    public function testCommitFailureCasRejectsTerminalFailedWithoutResult(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());
        $repo = new CompactionRepository($connection);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $repo->ensureRequest('req-failed-cas', 'run-failed-cas', 1, 3, 3, 'fp-failed-cas', $now);
        // Terminal failed request with no result row: exercises assertIdentityAndCasFailed 0-row CAS,
        // not the early timed_out guard or same-code result noop path.
        $connection->executeStatement(
            'UPDATE om_compaction_request SET status = ?, updated_at = ?, completed_at = ?, failure_code = ? WHERE request_id = ?',
            [CompactionRepository::STATUS_FAILED, $now, $now, 'prior_failure', 'req-failed-cas'],
        );

        try {
            $repo->commitFailure(
                requestId: 'req-failed-cas',
                resultId: 'res-failed-cas',
                runId: 'run-failed-cas',
                requiredStartSeq: 1,
                requiredEndSeq: 3,
                requiredWatermark: 3,
                requestFingerprint: 'fp-failed-cas',
                failureCode: 'tool_not_called',
                now: $now,
                failureMetadata: ['exception_class' => 'RuntimeException'],
            );
            $this->fail('commitFailure against terminal failed request must conflict via CAS');
        } catch (OmConflictException) {
            // expected
        }

        $status = $repo->getRequestStatus('req-failed-cas');
        $this->assertNotNull($status);
        $this->assertSame(CompactionRepository::STATUS_FAILED, $status['status']);
        $this->assertSame('prior_failure', $status['failure_code'] ?? null);
        $this->assertNull($repo->getResult('req-failed-cas'));
        $this->assertSame(
            0,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM om_compaction_result WHERE request_id = ?',
                ['req-failed-cas'],
            ),
        );
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
