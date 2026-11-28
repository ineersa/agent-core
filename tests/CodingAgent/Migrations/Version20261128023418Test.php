<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261128023418;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class Version20261128023418Test extends IsolatedKernelTestCase
{
    public function testForwardRebuildPreservesPopulatedRowsAndIndexes(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $tables = ['tool_batch', 'tool_deferred_completion'];
        foreach ($tables as $table) {
            $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table));
        }
        // The generated down SQL prepares the old EMPTY shape only. Populated
        // downgrade is unsupported; runtime migration is forward-only.
        $this->executeMigration($db, 'down');
        $db->insert('tool_batch', [
            'run_id' => 'migration-run', 'turn_no' => 1, 'step_id' => 'step',
            'finalized' => 0, 'expected_order_json' => '{"call":0}',
            'pending_queue_json' => '[]', 'in_flight_json' => '{"call":true}',
            'awaiting_human_input_json' => '{}', 'applied_transition' => 'transition',
            'max_parallelism' => 4,
        ]);
        $db->insert('tool_deferred_completion', [
            'deferred_id' => 'deferred', 'run_id' => 'migration-run', 'turn_no' => 1,
            'step_id' => 'step', 'attempt' => 1, 'idempotency_key' => 'key',
            'tool_call_id' => 'call', 'tool_name' => 'code_mode',
            'body_reference_json' => '{"fixture":"body"}', 'order_index' => 0,
            'tool_idempotency_key' => 'tool-key', 'mode' => 'parallel',
            'timeout_seconds' => 60, 'max_parallelism' => 4, 'tools_ref' => 'tools',
            'status' => 'pending', 'created_at' => '2026-11-28 00:00:00',
            'updated_at' => '2026-11-28 00:00:01', 'continuation_required' => 1,
            'accepted_result_reference_json' => '{"fixture":"result"}',
        ]);
        $before = [];
        $indexes = [];
        foreach ($tables as $table) {
            $before[$table] = $db->fetchAssociative('SELECT * FROM '.$table);
            unset($before[$table]['max_parallelism']);
            $indexes[$table] = array_keys($db->createSchemaManager()->listTableIndexes($table));
        }
        $this->executeMigration($db, 'up');
        foreach ($tables as $table) {
            $this->assertSame($before[$table], $db->fetchAssociative('SELECT * FROM '.$table));
            $this->assertArrayNotHasKey('max_parallelism', $db->createSchemaManager()->listTableColumns($table));
            $this->assertEqualsCanonicalizing($indexes[$table], array_keys($db->createSchemaManager()->listTableIndexes($table)));
        }
        $this->assertArrayHasKey('uniq_tool_deferred_completion_run_tool_call', $db->createSchemaManager()->listTableIndexes('tool_deferred_completion'));
        $this->assertArrayHasKey('idx_deferred_active_tool', $db->createSchemaManager()->listTableIndexes('tool_deferred_completion'));
    }

    private function executeMigration(Connection $db, string $direction): void
    {
        $migration = new Version20261128023418($db, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }
    }
}
