<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261102143815;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class Version20261102143815Test extends IsolatedKernelTestCase
{
    public function testGeneratedRebuildPreservesRowsAndExistingIndexes(): void
    {
        $container = self::getContainer();
        $db = $container->get(Connection::class);
        $root = $container->get(HatfieldSessionStore::class)->createSession('migration');
        $container->get(DeferredSubagentBatchRepository::class)->reserveBatch(
            lifecycleId: 'migration-batch', parentRunId: $root, parentTurnNo: 1,
            parentToolCallId: 'tool', parentOrderIndex: 0, deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntent: new DeferredSubagentBatchChildIntentDTO(1, 'migration-child', 'artifact', 'scout', 'task', 'provider/model', 'low'),
        );
        $db->update('deferred_subagent_child', ['child_event_cursor' => 42, 'child_lifecycle_projection' => '{"model":"provider/model"}', 'terminal_status' => 'completed'], ['child_run_id' => 'migration-child']);
        $correlation = new DeferredToolCompletionCorrelation('migration-deferred', $root, 1, 'step', 1, 'key', 'host', 'code_mode', [], 0);
        TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $correlation);
        $container->get(DeferredToolCompletionRepository::class)->registerPending($correlation);
        $tables = ['deferred_subagent_child', 'tool_deferred_completion'];
        $before = [];
        $indexes = [];
        foreach ($tables as $table) {
            $before[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
            $indexes[$table] = array_keys($db->createSchemaManager()->listTableIndexes($table));
        }
        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261102143815($db, new NullLogger());
            $migration->$direction(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement());
            }
            foreach ($tables as $table) {
                $this->assertEquals($before[$table], $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id'));
            }
        }
        foreach ($tables as $table) {
            $this->assertEqualsCanonicalizing($indexes[$table], array_keys($db->createSchemaManager()->listTableIndexes($table)));
        }
        $this->assertArrayHasKey('idx_child_active', $db->createSchemaManager()->listTableIndexes('deferred_subagent_child'));
        $this->assertArrayHasKey('idx_deferred_active_tool', $db->createSchemaManager()->listTableIndexes('tool_deferred_completion'));
    }
}
