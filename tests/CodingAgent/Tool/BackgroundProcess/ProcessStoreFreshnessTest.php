<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use PHPUnit\Framework\Attributes\Test;

/**
 * Background-process reads must observe committed mutable fields without
 * detaching entities needed for later status mutation and flush.
 */
final class ProcessStoreFreshnessTest extends IsolatedKernelTestCase
{
    private ProcessStore $store;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ProcessStore $store */
        $store = self::getContainer()->get(ProcessStore::class);
        $this->store = $store;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    #[Test]
    public function fetchByRecordIdSeesStatusCommittedByExternalWriterAndKeepsEntityManaged(): void
    {
        $recordId = $this->store->insertRecord([
            'pid' => 4242,
            'pgid' => 4242,
            'session_id' => 'session-fresh',
            'command' => 'sleep 1',
            'log_path' => '/tmp/bg-fresh.log',
            'status_path' => '/tmp/bg-fresh.status',
            'started_at' => new \DateTimeImmutable('2026-09-16 12:00:00'),
        ]);

        $cached = $this->store->fetchByRecordId($recordId);
        $this->assertNotNull($cached);
        $this->assertNull($cached->finishedAt);
        $this->assertSame(BackgroundProcessStatusEnum::Running, $cached->status);

        $finishedAt = '2026-09-16 12:01:00';
        $this->connection->executeStatement(
            'UPDATE background_process
             SET finished_at = :finished_at,
                 exit_code = :exit_code,
                 status = :status,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'finished_at' => $finishedAt,
                'exit_code' => 0,
                'status' => BackgroundProcessStatusEnum::Finished->value,
                'updated_at' => $finishedAt,
                'id' => $recordId,
            ],
        );

        $fresh = $this->store->fetchByRecordId($recordId);
        $this->assertNotNull($fresh);
        $this->assertSame($cached, $fresh, 'fresh reads must reuse the managed identity-map entity');
        $this->assertTrue($this->entityManager->contains($fresh));
        $this->assertSame(BackgroundProcessStatusEnum::Finished, $fresh->status);
        $this->assertSame(0, $fresh->exitCode);
        $this->assertNotNull($fresh->finishedAt);
        $this->assertSame($finishedAt, $fresh->finishedAt->format('Y-m-d H:i:s'));

        $notifiedAt = new \DateTimeImmutable('2026-09-16 12:02:00');
        $fresh->markCompletionNotified($notifiedAt);
        $this->store->flush();

        $row = $this->connection->fetchAssociative(
            'SELECT completion_notified_at FROM background_process WHERE id = :id',
            ['id' => $recordId],
        );
        $this->assertIsArray($row);
        $this->assertSame('2026-09-16 12:02:00', $row['completion_notified_at']);
    }

    #[Test]
    public function fetchAllUnfinishedRefreshesMutableFieldsInOneQueryResult(): void
    {
        $recordId = $this->store->insertRecord([
            'pid' => 5252,
            'session_id' => 'session-list-fresh',
            'command' => 'sleep 2',
            'log_path' => '/tmp/bg-list.log',
            'status_path' => '/tmp/bg-list.status',
            'started_at' => new \DateTimeImmutable('2026-09-16 12:00:00'),
        ]);

        $entities = $this->store->fetchAllUnfinished('session-list-fresh');
        $this->assertCount(1, $entities);
        $this->assertSame($recordId, $entities[0]->id);
        $this->assertSame('sleep 2', $entities[0]->command);

        $this->connection->executeStatement(
            'UPDATE background_process
             SET command = :command,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'command' => 'sleep 2 --external',
                'updated_at' => '2026-09-16 12:05:00',
                'id' => $recordId,
            ],
        );

        $again = $this->store->fetchAllUnfinished('session-list-fresh');
        $this->assertCount(1, $again);
        $this->assertSame($entities[0], $again[0]);
        $this->assertTrue($this->entityManager->contains($again[0]));
        $this->assertSame('sleep 2 --external', $again[0]->command);

        $again[0]->exitCode = 7;
        $this->store->flush();

        $row = $this->connection->fetchAssociative(
            'SELECT command, exit_code FROM background_process WHERE id = :id',
            ['id' => $recordId],
        );
        $this->assertIsArray($row);
        $this->assertSame('sleep 2 --external', $row['command']);
        $this->assertSame(7, (int) $row['exit_code']);
    }

    #[Test]
    public function deleteByIdUsesDatabaseExistenceNotStaleIdentityMap(): void
    {
        $recordId = $this->store->insertRecord([
            'pid' => 6262,
            'session_id' => 'session-delete-fresh',
            'command' => 'sleep 3',
            'log_path' => '/tmp/bg-delete.log',
            'status_path' => '/tmp/bg-delete.status',
            'started_at' => new \DateTimeImmutable('2026-09-16 12:00:00'),
        ]);

        $entity = $this->entityManager->find(BackgroundProcess::class, $recordId);
        $this->assertNotNull($entity);

        $this->connection->executeStatement(
            'DELETE FROM background_process WHERE id = :id',
            ['id' => $recordId],
        );

        $this->assertFalse($this->store->deleteById($recordId));
        $this->assertTrue($this->entityManager->contains($entity));
    }
}
