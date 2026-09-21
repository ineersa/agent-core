<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Child mutable cursor/status reads and resume rebind must use committed values,
 * then detach only the rebound child after DBAL update.
 */
#[Group('db')]
final class DeferredSubagentChildRepositoryFreshnessTest extends IsolatedKernelTestCase
{
    private DeferredSubagentBatchRepository $batchRepository;
    private DeferredSubagentChildRepository $childRepository;
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DeferredSubagentBatchIdentityFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DeferredSubagentBatchRepository $batchRepository */
        $batchRepository = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->batchRepository = $batchRepository;

        /** @var DeferredSubagentChildRepository $childRepository */
        $childRepository = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $this->childRepository = $childRepository;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->factory = new DeferredSubagentBatchIdentityFactory();
    }

    #[Test]
    public function findByChildRunIdSeesCursorCommittedOutsideIdentityMap(): void
    {
        [$lifecycle, $childRunId] = $this->reserveOneChild('parent-child-fresh-cursor', 'tool-child-fresh-cursor');

        $before = $this->childRepository->findByChildRunId($childRunId);
        $this->assertNotNull($before);
        $this->assertSame(0, $before->childEventCursor);

        $managed = $this->entityManager->getRepository(DeferredSubagentChild::class)->findOneBy([
            'childRunId' => $childRunId,
        ]);
        $this->assertInstanceOf(DeferredSubagentChild::class, $managed);

        $this->connection->executeStatement(
            'UPDATE deferred_subagent_child
             SET child_event_cursor = :cursor,
                 updated_at = :now,
                 projection_version = projection_version + 1
             WHERE child_run_id = :child_run_id',
            [
                'cursor' => 17,
                'now' => '2026-09-16 14:00:00',
                'child_run_id' => $childRunId,
            ],
        );

        $after = $this->childRepository->findByChildRunId($childRunId);
        $this->assertNotNull($after);
        $this->assertSame(17, $after->childEventCursor);
        $this->assertSame(17, $managed->childEventCursor);
        $this->assertTrue($this->entityManager->contains($managed));

        $ordered = $this->childRepository->findOrderedByBatchLifecycleId($lifecycle);
        $this->assertCount(1, $ordered);
        $this->assertSame(17, $ordered[0]->childEventCursor);
    }

    #[Test]
    public function rebindExistingChildToResumeBatchPreservesFreshCursorAndDetachesManagedChild(): void
    {
        [$oldLifecycle, $childRunId] = $this->reserveOneChild('parent-child-fresh-rebind', 'tool-child-fresh-rebind');
        unset($oldLifecycle);

        $managed = $this->entityManager->getRepository(DeferredSubagentChild::class)->findOneBy([
            'childRunId' => $childRunId,
        ]);
        $this->assertInstanceOf(DeferredSubagentChild::class, $managed);
        $this->assertSame(0, $managed->childEventCursor);

        $this->connection->executeStatement(
            'UPDATE deferred_subagent_child
             SET child_event_cursor = :cursor,
                 launch_status = :launched,
                 terminal_status = :terminal,
                 terminal_completed_at = :completed_at,
                 updated_at = :now,
                 projection_version = projection_version + 1
             WHERE child_run_id = :child_run_id',
            [
                'cursor' => 42,
                'launched' => DeferredSubagentChildLaunchStatusEnum::Launched->value,
                'terminal' => 'completed',
                'completed_at' => '2026-09-16 14:01:00',
                'now' => '2026-09-16 14:01:00',
                'child_run_id' => $childRunId,
            ],
        );

        $resumeParent = 'parent-child-fresh-rebind-resume';
        $resumeTool = 'tool-child-fresh-rebind-resume';
        $resumeLifecycle = $this->factory->batchLifecycleId($resumeParent, $resumeTool);
        $resumeIdentity = $this->factory->childIdentity($resumeParent, $resumeTool, 1);

        $this->childRepository->rebindExistingChildToResumeBatch(
            batchLifecycleId: $resumeLifecycle,
            batchIndex: 1,
            childRunId: $childRunId,
            artifactId: $resumeIdentity['artifactId'],
            agentName: 'fresh-agent',
            task: 'resume task',
            launchModel: 'deepseek/deepseek-v4-flash',
            launchReasoning: 'medium',
        );

        $this->assertFalse($this->entityManager->contains($managed));

        $row = $this->connection->fetchAssociative(
            'SELECT batch_lifecycle_id, batch_index, child_event_cursor, launch_status, terminal_status, terminal_completed_at
             FROM deferred_subagent_child WHERE child_run_id = :child_run_id',
            ['child_run_id' => $childRunId],
        );
        $this->assertIsArray($row);
        $this->assertSame($resumeLifecycle, $row['batch_lifecycle_id']);
        $this->assertSame(1, (int) $row['batch_index']);
        $this->assertSame(42, (int) $row['child_event_cursor']);
        $this->assertSame(DeferredSubagentChildLaunchStatusEnum::Reserved->value, $row['launch_status']);
        $this->assertNull($row['terminal_status']);
        $this->assertNull($row['terminal_completed_at']);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function reserveOneChild(string $parent, string $tool): array
    {
        $lifecycle = $this->factory->batchLifecycleId($parent, $tool);
        $child = $this->factory->childIdentity($parent, $tool, 1);
        $this->batchRepository->reserveBatch(
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

        return [$lifecycle, $child['childRunId']];
    }
}
