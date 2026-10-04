<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use DoctrineMigrations\Version20261004192458;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatch;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class Version20261004192458Test extends IsolatedKernelTestCase
{
    public function testExistingChildrenReceiveDistinctKeysAndUsageIsRebuiltFromEvents(): void
    {
        $container = static::getContainer();
        $repository = $container->get(DeferredSubagentChildRepository::class);
        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        $connection = $entityManager->getConnection();
        foreach (['migration-cache-batch' => null, 'migration-terminal-batch' => new \DateTimeImmutable('2026-10-04')] as $lifecycle => $completedAt) {
            $batch = new DeferredSubagentBatch();
            $batch->lifecycleId = $lifecycle;
            $batch->parentRunId = 'migration-parent';
            $batch->parentToolCallId = $lifecycle;
            $batch->totalChildCount = 1;
            $batch->terminalCompletionEnqueuedAt = $completedAt;
            $entityManager->persist($batch);
        }
        $entityManager->flush();

        $repository->insertReservedChildren('migration-cache-batch', [
            ['batchIndex' => 1, 'childRunId' => 'child-one', 'artifactId' => 'agent_one', 'agentName' => 'worker', 'task' => 'one', 'launchModel' => 'test/model', 'launchReasoning' => 'medium'],
            ['batchIndex' => 2, 'childRunId' => 'child-two', 'artifactId' => 'agent_two', 'agentName' => 'worker', 'task' => 'two', 'launchModel' => 'test/model', 'launchReasoning' => 'medium'],
        ]);
        $connection->update('deferred_subagent_child', ['batch_lifecycle_id' => 'migration-terminal-batch', 'batch_index' => 1], ['child_run_id' => 'child-two']);
        $connection->executeStatement('UPDATE deferred_subagent_child SET child_event_cursor = 42, child_lifecycle_projection = ?', ['{"input_tokens":100}']);

        $down = new Version20261004192458($connection, new NullLogger());
        $down->down($connection->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $up = new Version20261004192458($connection, new NullLogger());
        $up->up($connection->createSchemaManager()->introspectSchema());
        foreach ($up->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $one = $repository->findProviderCacheKey('child-one');
        $two = $repository->findProviderCacheKey('child-two');
        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($one));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($two));
        $this->assertNotSame($one, $two);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT child_event_cursor FROM deferred_subagent_child WHERE child_run_id = ?', ['child-one']));
        $this->assertNull($connection->fetchOne('SELECT child_lifecycle_projection FROM deferred_subagent_child WHERE child_run_id = ?', ['child-one']));
        $this->assertSame(42, (int) $connection->fetchOne('SELECT child_event_cursor FROM deferred_subagent_child WHERE child_run_id = ?', ['child-two']));
        $this->assertSame('{"input_tokens":100}', $connection->fetchOne('SELECT child_lifecycle_projection FROM deferred_subagent_child WHERE child_run_id = ?', ['child-two']));
    }
}
