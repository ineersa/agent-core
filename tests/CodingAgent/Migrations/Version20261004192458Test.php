<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use DoctrineMigrations\Version20261004192458;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatch;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class Version20261004192458Test extends IsolatedKernelTestCase
{
    public function testExistingChildrenReceiveDistinctKeysWithoutChangingCheckpoints(): void
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

        $this->migrateLegacyChildTable();

        $one = $repository->findProviderCacheKey('child-one');
        $two = $repository->findProviderCacheKey('child-two');
        $this->assertNotNull($one);
        $this->assertNotNull($two);
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($one));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($two));
        $this->assertNotSame($one, $two);
        $this->assertSame(42, (int) $connection->fetchOne('SELECT child_event_cursor FROM deferred_subagent_child WHERE child_run_id = ?', ['child-one']));
        $this->assertSame('{"input_tokens":100}', $connection->fetchOne('SELECT child_lifecycle_projection FROM deferred_subagent_child WHERE child_run_id = ?', ['child-one']));
        $this->assertSame(42, (int) $connection->fetchOne('SELECT child_event_cursor FROM deferred_subagent_child WHERE child_run_id = ?', ['child-two']));
        $this->assertSame('{"input_tokens":100}', $connection->fetchOne('SELECT child_lifecycle_projection FROM deferred_subagent_child WHERE child_run_id = ?', ['child-two']));
    }

    #[DataProvider('resumeStates')]
    public function testMigrationPreservesResumeBoundaryDuringActualRecovery(bool $resultCommitted): void
    {
        TestDirectoryIsolation::createHatfieldTree((string) getcwd(), withSessions: true);
        $container = static::getContainer();
        $em = $container->get('doctrine.orm.default_entity_manager');
        $connection = $em->getConnection();
        foreach (['old-batch', 'resume-batch'] as $lifecycle) {
            $batch = new DeferredSubagentBatch();
            $batch->lifecycleId = $lifecycle;
            $batch->parentRunId = 'migration-parent';
            $batch->parentToolCallId = $lifecycle;
            $batch->totalChildCount = 1;
            $batch->terminalCompletionEnqueuedAt = 'old-batch' === $lifecycle ? new \DateTimeImmutable('2026-10-04') : null;
            $em->persist($batch);
        }
        $em->flush();
        $childId = Uuid::v4()->toRfc4122();
        $repository = $container->get(DeferredSubagentChildRepository::class);
        $repository->insertReservedChildren('old-batch', [[
            'batchIndex' => 1, 'childRunId' => $childId, 'artifactId' => 'agent_resume',
            'agentName' => 'fork', 'task' => 'old task', 'launchModel' => 'test/model', 'launchReasoning' => 'medium',
        ]]);
        $store = $container->get(AgentChildRunEventStoreFactory::class)->create('migration-parent', $childId, 'agent_resume');
        $store->append(RunEvent::forAppend($childId, 1, 'llm_step_completed', [
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10, 'total_tokens' => 110, 'cost' => 0.1],
            'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'old result']]],
        ]));
        $terminal = $store->append(RunEvent::forAppend($childId, 1, 'agent_end', ['reason' => 'completed']));
        $legacy = new DeferredChildRunLifecycleProjectionDTO(
            RunStatus::Completed, 1, $terminal->seq, 'test/model', 'medium',
            assistantResultText: 'old result', llmStepCount: 1, inputTokens: 100,
            outputTokens: 10, totalTokens: 110, cost: 0.1,
        );
        $connection->update('deferred_subagent_child', [
            'child_event_cursor' => $terminal->seq,
            'child_lifecycle_projection' => $container->get(SerializerInterface::class)->serialize($legacy, 'json', [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true]),
        ], ['child_run_id' => $childId]);
        $repository->rebindExistingChildToResumeBatch('resume-batch', 1, $childId, 'agent_resume', 'fork', 'resume task', 'test/model', 'medium');
        $recovery = $container->get(DeferredSubagentBatchRecoveryService::class);
        if ($resultCommitted) {
            $store->append(RunEvent::forAppend($childId, 2, 'agent_command_queued', ['command_kind' => 'follow_up']));
            $store->append(RunEvent::forAppend($childId, 2, 'llm_step_completed', [
                'usage' => ['input_tokens' => 20, 'output_tokens' => 2, 'total_tokens' => 22, 'cost' => 0.02, 'cache_read_tokens' => 18],
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'resumed result']]],
            ]));
            $recovery->recover('resume-batch');
        }
        $before = $connection->fetchAssociative('SELECT child_event_cursor, child_lifecycle_projection, projection_version FROM deferred_subagent_child WHERE child_run_id = ?', [$childId]);
        $this->migrateLegacyChildTable();
        $em->clear();
        $afterMigration = $connection->fetchAssociative('SELECT child_event_cursor, child_lifecycle_projection, projection_version FROM deferred_subagent_child WHERE child_run_id = ?', [$childId]);
        $recovery->recover('resume-batch');
        $projection = $repository->findByChildRunId($childId)?->childLifecycleProjection;
        $this->assertNotNull($projection);
        $this->assertSame(RunStatus::Running, $projection->childStatus);
        $this->assertSame($resultCommitted ? 20 : 0, $projection->inputTokens);
        $this->assertSame($resultCommitted ? 2 : 0, $projection->outputTokens);
        $this->assertSame($resultCommitted ? 22 : 0, $projection->totalTokens);
        $this->assertSame($resultCommitted ? 1 : 0, $projection->llmStepCount);
        $this->assertSame($resultCommitted ? 0.02 : null, $projection->cost);
        $this->assertSame($resultCommitted ? 'resumed result' : null, $projection->assistantResultText);
        $this->assertNull($projection->cacheInputTokens);
        $this->assertNull($projection->cacheReadTokens);
        $this->assertSame($before, $afterMigration);
        $key = $repository->findProviderCacheKey($childId);
        $this->assertNotNull($key);
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($key));
    }

    public static function resumeStates(): iterable
    {
        yield 'follow-up not yet observed' => [false];
        yield 'resumed LLM result already observed' => [true];
    }

    private function migrateLegacyChildTable(): void
    {
        $connection = static::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
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
    }
}
