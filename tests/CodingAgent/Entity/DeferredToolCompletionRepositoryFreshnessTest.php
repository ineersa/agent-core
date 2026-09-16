<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Entity\DeferredToolCompletion;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutable deferred-tool status reads must observe DBAL writes from another
 * connection without refreshing immutable registration fields.
 */
#[Group('db')]
final class DeferredToolCompletionRepositoryFreshnessTest extends IsolatedKernelTestCase
{
    private DeferredToolCompletionRepository $repository;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DeferredToolCompletionRepository $repository */
        $repository = self::getContainer()->get(DeferredToolCompletionRepository::class);
        $this->repository = $repository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    #[Test]
    public function statusAndPendingLookupSeeCompletionCommittedOutsideIdentityMap(): void
    {
        $correlation = $this->repository->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440100',
            runId: 'run-fresh-1',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-fresh',
            toolCallId: 'call-fresh',
            toolName: 'read',
            arguments: ['path' => './a.txt'],
            orderIndex: 0,
        ));

        $this->assertSame('pending', $this->repository->status($correlation->deferredId));
        $pending = $this->repository->findPendingByRunAndToolCall('run-fresh-1', 'call-fresh');
        $this->assertNotNull($pending);

        $managed = $this->entityManager->getRepository(DeferredToolCompletion::class)->findOneBy([
            'deferredId' => $correlation->deferredId,
        ]);
        $this->assertInstanceOf(DeferredToolCompletion::class, $managed);
        $this->assertSame('pending', $managed->status);

        $this->connection->executeStatement(
            'UPDATE deferred_tool_completion
             SET status = :completed, updated_at = :now
             WHERE deferred_id = :deferred_id',
            [
                'completed' => 'completed',
                'now' => '2026-09-16 12:10:00',
                'deferred_id' => $correlation->deferredId,
            ],
        );

        $this->assertSame('completed', $this->repository->status($correlation->deferredId));
        $this->assertNull($this->repository->findPendingByRunAndToolCall('run-fresh-1', 'call-fresh'));
        $this->assertSame('completed', $managed->status);
        $this->assertTrue($this->entityManager->contains($managed));
    }

    #[Test]
    public function markCompletedFallbackDoesNotRewriteAlreadyCompletedRowFromStaleCache(): void
    {
        $correlation = $this->repository->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440101',
            runId: 'run-fresh-2',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-fresh-2',
            toolCallId: 'call-fresh-2',
            toolName: 'read',
            arguments: [],
            orderIndex: 0,
        ));

        $managed = $this->entityManager->getRepository(DeferredToolCompletion::class)->findOneBy([
            'deferredId' => $correlation->deferredId,
        ]);
        $this->assertInstanceOf(DeferredToolCompletion::class, $managed);
        $this->assertSame('pending', $managed->status);

        // External writer completes the row while this EM still caches pending.
        $this->connection->executeStatement(
            'UPDATE deferred_tool_completion
             SET status = :completed, updated_at = :now
             WHERE deferred_id = :deferred_id AND status = :pending',
            [
                'completed' => 'completed',
                'pending' => 'pending',
                'now' => '2026-09-16 12:11:00',
                'deferred_id' => $correlation->deferredId,
            ],
        );

        // Local markCompleted SQL matches zero rows; fallback must refresh before deciding.
        $this->repository->markCompleted($correlation->deferredId);

        $row = $this->connection->fetchAssociative(
            'SELECT status, updated_at FROM deferred_tool_completion WHERE deferred_id = :deferred_id',
            ['deferred_id' => $correlation->deferredId],
        );
        $this->assertIsArray($row);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('2026-09-16 12:11:00', $row['updated_at']);
        $this->assertSame('completed', $managed->status);
    }

    #[Test]
    public function findByDeferredIdKeepsImmutableRegistrationWithoutStatusRefreshRequirement(): void
    {
        $correlation = $this->repository->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440102',
            runId: 'run-fresh-3',
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-fresh-3',
            toolCallId: 'call-fresh-3',
            toolName: 'bash',
            arguments: ['command' => 'true'],
            orderIndex: 1,
            timeoutSeconds: 15,
        ));

        $loaded = $this->repository->findByDeferredId($correlation->deferredId);
        $this->assertNotNull($loaded);
        $this->assertSame('bash', $loaded->toolName);
        $this->assertSame(15, $loaded->timeoutSeconds);
        $this->assertSame('run-fresh-3', $loaded->runId);
    }
}
