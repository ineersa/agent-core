<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatch;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Batch mutable fields and version-guarded marker writes must refresh from SQL
 * before deciding, without replacing existing post-transaction clears.
 */
#[Group('db')]
final class DeferredSubagentBatchRepositoryFreshnessTest extends IsolatedKernelTestCase
{
    private DeferredSubagentBatchRepository $repository;
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DeferredSubagentBatchIdentityFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DeferredSubagentBatchRepository $repository */
        $repository = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->repository = $repository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->factory = new DeferredSubagentBatchIdentityFactory();
    }

    #[Test]
    public function findByLifecycleIdSeesLaunchStatusCommittedOutsideIdentityMap(): void
    {
        $parent = 'parent-batch-fresh-status';
        $tool = 'tool-batch-fresh-status';
        $lifecycle = $this->reserveOneChild($parent, $tool);

        $before = $this->repository->findByLifecycleId($lifecycle);
        $this->assertNotNull($before);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $before->launchStatus);

        $managed = $this->entityManager->getRepository(DeferredSubagentBatch::class)->findOneBy([
            'lifecycleId' => $lifecycle,
        ]);
        $this->assertInstanceOf(DeferredSubagentBatch::class, $managed);

        $this->connection->executeStatement(
            'UPDATE deferred_subagent_batch
             SET launch_status = :launched, updated_at = :now, projection_version = projection_version + 1
             WHERE lifecycle_id = :lifecycle',
            [
                'launched' => DeferredSubagentBatchLaunchStatusEnum::Launched->value,
                'now' => '2026-09-16 13:00:00',
                'lifecycle' => $lifecycle,
            ],
        );

        $after = $this->repository->findByLifecycleId($lifecycle);
        $this->assertNotNull($after);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $after->launchStatus);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $managed->launchStatus);
        $this->assertTrue($this->entityManager->contains($managed));
    }

    #[Test]
    public function markDeliveredProgressRevisionRejectsStaleCachedProjectionVersion(): void
    {
        $parent = 'parent-batch-fresh-version';
        $tool = 'tool-batch-fresh-version';
        $lifecycle = $this->reserveOneChild($parent, $tool);

        $dto = $this->repository->findByLifecycleId($lifecycle);
        $this->assertNotNull($dto);
        $cachedVersion = $dto->projectionVersion;

        $this->connection->executeStatement(
            'UPDATE deferred_subagent_batch
             SET aggregate_progress_revision = aggregate_progress_revision + 1,
                 updated_at = :now,
                 projection_version = projection_version + 1
             WHERE lifecycle_id = :lifecycle',
            [
                'now' => '2026-09-16 13:01:00',
                'lifecycle' => $lifecycle,
            ],
        );

        $this->expectException(OptimisticLockException::class);
        $this->repository->markDeliveredProgressRevision($lifecycle, 1, $cachedVersion);
    }

    #[Test]
    public function persistInterruptionIntentUsesRefreshedMarkersBeforeFirstWinsAssign(): void
    {
        $parent = 'parent-batch-fresh-interrupt';
        $tool = 'tool-batch-fresh-interrupt';
        $lifecycle = $this->reserveOneChild($parent, $tool);

        $dto = $this->repository->findByLifecycleId($lifecycle);
        $this->assertNotNull($dto);
        $version = $dto->projectionVersion;

        $this->connection->executeStatement(
            'UPDATE deferred_subagent_batch
             SET interruption_kind = :kind,
                 interruption_requested_at = :requested_at,
                 updated_at = :now
             WHERE lifecycle_id = :lifecycle',
            [
                'kind' => DeferredSubagentInterruptionKindEnum::ParentCancelled->value,
                'requested_at' => '2026-09-16 13:02:00',
                'now' => '2026-09-16 13:02:00',
                'lifecycle' => $lifecycle,
            ],
        );

        $this->repository->persistInterruptionIntent(
            $lifecycle,
            DeferredSubagentInterruptionKindEnum::Timeout,
            new \DateTimeImmutable('2026-09-16 13:03:00'),
            $version,
        );

        $row = $this->connection->fetchAssociative(
            'SELECT interruption_kind, interruption_requested_at FROM deferred_subagent_batch WHERE lifecycle_id = :lifecycle',
            ['lifecycle' => $lifecycle],
        );
        $this->assertIsArray($row);
        $this->assertSame(DeferredSubagentInterruptionKindEnum::ParentCancelled->value, $row['interruption_kind']);
        $this->assertSame('2026-09-16 13:02:00', $row['interruption_requested_at']);
    }

    #[Test]
    public function findUnfinishedByParentRunIdRefreshesMutableFieldsInListResult(): void
    {
        $parent = 'parent-batch-fresh-list';
        $tool = 'tool-batch-fresh-list';
        $lifecycle = $this->reserveOneChild($parent, $tool);

        $first = $this->repository->findUnfinishedByParentRunId($parent);
        $this->assertCount(1, $first);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Reserved, $first[0]->launchStatus);

        $this->connection->executeStatement(
            'UPDATE deferred_subagent_batch
             SET launch_status = :launched, updated_at = :now, projection_version = projection_version + 1
             WHERE lifecycle_id = :lifecycle',
            [
                'launched' => DeferredSubagentBatchLaunchStatusEnum::Launched->value,
                'now' => '2026-09-16 13:04:00',
                'lifecycle' => $lifecycle,
            ],
        );

        $again = $this->repository->findUnfinishedByParentRunId($parent);
        $this->assertCount(1, $again);
        $this->assertSame(DeferredSubagentBatchLaunchStatusEnum::Launched, $again[0]->launchStatus);
    }

    private function reserveOneChild(string $parent, string $tool): string
    {
        $lifecycle = $this->factory->batchLifecycleId($parent, $tool);
        $child = $this->factory->childIdentity($parent, $tool, 1);
        $this->repository->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [[
                'batchIndex' => 1,
                'childRunId' => $child['childRunId'],
                'artifactId' => $child['artifactId'],
                'agentName' => 'fresh-agent',
                'task' => 'fresh task',
                'launchModel' => 'deepseek/deepseek-v4-flash',
                'launchReasoning' => 'medium',
            ]],
        );

        return $lifecycle;
    }
}
