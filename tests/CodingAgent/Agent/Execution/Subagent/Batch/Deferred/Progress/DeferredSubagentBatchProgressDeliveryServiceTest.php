<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressSnapshotFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatch;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\TestWith;

final class DeferredSubagentBatchProgressDeliveryServiceTest extends IsolatedKernelTestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testMarkerRefreshesOnlyBatchAndDoesNotRegressDeliveredRevision(bool $alreadyDelivered): void
    {
        $batch = $this->reserve();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        // Keep the batch in the identity map, then mutate SQL after append. The
        // marker must refresh versions without decoding unrelated child payloads.
        $managed = $repo->findEntityByLifecycleId($batch->lifecycleId);
        $this->assertNotNull($managed);
        $connection->executeStatement('UPDATE deferred_subagent_child SET child_lifecycle_projection = ? WHERE batch_lifecycle_id = ?', ['not JSON', $batch->lifecycleId]);
        $appender = $this->createStub(SubagentProgressEventAppender::class);
        $appender->method('append')->willReturnCallback(static function () use ($connection, $batch, $alreadyDelivered): bool {
            $connection->executeStatement('UPDATE deferred_subagent_batch SET projection_version = projection_version + 1, delivered_progress_revision = ? WHERE lifecycle_id = ?', [$alreadyDelivered ? 3 : 0, $batch->lifecycleId]);

            return true;
        });
        $sql = self::getContainer()->get('test.sql_logger');
        $sql->records = [];
        $service = $this->service($appender, new TestLogger());
        $this->assertTrue($service->deliverIfNeeded($batch));
        $this->assertSame($alreadyDelivered ? 3 : 2, $managed->deliveredProgressRevision);
        $this->assertGreaterThan($batch->projectionVersion, $managed->projectionVersion);
        $this->assertSame([], array_values(array_filter($sql->records, static fn (array $row): bool => str_contains($row['context']['sql'] ?? '', 'deferred_subagent_child'))));
        $fresh = $repo->findEntityByLifecycleId($batch->lifecycleId);
        $this->assertNotNull($fresh);
        $this->assertSame($alreadyDelivered ? 3 : 2, $fresh->deliveredProgressRevision);
    }

    public function testConflictBetweenMarkerReadAndWriteIsLoggedAndRetryable(): void
    {
        $batch = $this->reserve();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class($em) {
            public bool $fired = false;

            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            public function postLoad(PostLoadEventArgs $event): void
            {
                $entity = $event->getObject();
                if ($this->fired || !$entity instanceof DeferredSubagentBatch) {
                    return;
                }
                $this->fired = true;
                // Deterministic conflict after the first refresh has hydrated its
                // version, before markDeliveredProgressRevision refreshes again.
                $this->entityManager->getConnection()->executeStatement('UPDATE deferred_subagent_batch SET projection_version = projection_version + 1 WHERE lifecycle_id = ?', [$entity->lifecycleId]);
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $listener);
        $appender = $this->createStub(SubagentProgressEventAppender::class);
        $appender->method('append')->willReturn(true);
        $logger = new TestLogger();
        $service = $this->service($appender, $logger);
        try {
            try {
                $service->deliverIfNeeded($batch);
                $this->fail('A concurrent projection version must reject the marker.');
            } catch (OptimisticLockException $exception) {
                $this->assertStringContainsString('optimistic lock', strtolower($exception->getMessage()));
            }
        } finally {
            $em->getEventManager()->removeEventListener([Events::postLoad], $listener);
        }
        $this->assertTrue($listener->fired);
        $this->assertSame('deferred_subagent_batch.delivered_progress_revision_conflict', $logger->records[0]['message']);
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $this->assertSame(0, $repo->findEntityByLifecycleId($batch->lifecycleId)?->deliveredProgressRevision);
        $this->assertTrue($service->deliverIfNeeded($batch));
        $this->assertSame(2, $repo->findEntityByLifecycleId($batch->lifecycleId)?->deliveredProgressRevision);
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }

    private function reserve(): DeferredSubagentBatchProjectionDTO
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $repo->reserveBatch('marker-lifecycle', 'marker-parent', 1, 'marker-tool', 0, new \DateTimeImmutable('+1 hour'), new DeferredSubagentBatchChildIntentDTO(1, 'marker-child', 'marker-artifact', 'worker', 'task', 'model', 'medium'));
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE deferred_subagent_batch SET aggregate_progress_revision = 2 WHERE lifecycle_id = ?', ['marker-lifecycle']);
        $batch = $repo->findByLifecycleId('marker-lifecycle');
        $this->assertNotNull($batch);

        return $batch;
    }

    private function service(SubagentProgressEventAppender $appender, TestLogger $logger): DeferredSubagentBatchProgressDeliveryService
    {
        return new DeferredSubagentBatchProgressDeliveryService(
            self::getContainer()->get(DeferredSubagentBatchRepository::class),
            self::getContainer()->get(DeferredSubagentBatchProgressSnapshotFactory::class),
            $appender,
            $logger,
        );
    }
}
