<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * This class defines a Doctrine migration to modify the database schema for the Agent Core infrastructure. It implements the standard up and down methods to apply or revert structural changes using the Doctrine Schema abstraction.
 */
final class Version20260418000100 extends AbstractMigration
{
    /**
     * Returns the human-readable description of this migration.
     */
    public function getDescription(): string
    {
        return 'Create agent loop stage-03 persistence schema (runs, commands, events, outbox, hot prompt, tool jobs).';
    }

    /**
     * Applies the database schema changes defined in this migration.
     */
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('agent_runs')) {
            $runs = $schema->createTable('agent_runs');
            $runs->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $runs->addColumn('public_id', Types::STRING, ['length' => 36]);
            $runs->addColumn('user_id', Types::STRING, ['length' => 191, 'notnull' => false]);
            $runs->addColumn('status', Types::STRING, ['length' => 32]);
            $runs->addColumn('version', Types::INTEGER, ['default' => 0]);
            $runs->addColumn('turn_no', Types::INTEGER, ['default' => 0]);
            $runs->addColumn('last_seq', Types::INTEGER, ['default' => 0]);
            $runs->addColumn('cancel_requested', Types::BOOLEAN, ['default' => false]);
            $runs->addColumn('waiting_human', Types::BOOLEAN, ['default' => false]);
            $runs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $runs->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $runs->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $runs->setPrimaryKey(['id']);
            $runs->addUniqueIndex(['public_id'], 'uniq_agent_runs_public_id');
            $runs->addIndex(['status'], 'idx_agent_runs_status');
        }

        if (!$schema->hasTable('agent_commands')) {
            $commands = $schema->createTable('agent_commands');
            $commands->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $commands->addColumn('run_id', Types::BIGINT);
            $commands->addColumn('kind', Types::STRING, ['length' => 128]);
            $commands->addColumn('payload_json', Types::JSON);
            $commands->addColumn('options_json', Types::JSON, ['notnull' => false]);
            $commands->addColumn('idempotency_key', Types::STRING, ['length' => 191]);
            $commands->addColumn('status', Types::STRING, ['length' => 32]);
            $commands->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $commands->addColumn('applied_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $commands->setPrimaryKey(['id']);
            $commands->addUniqueIndex(['run_id', 'idempotency_key'], 'uniq_agent_commands_run_idempotency');
            $commands->addIndex(['run_id', 'status', 'created_at'], 'idx_agent_commands_run_status_created');
            $commands->addForeignKeyConstraint('agent_runs', ['run_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_agent_commands_run');
        }

        if (!$schema->hasTable('agent_hot_prompt_state')) {
            $hotPrompt = $schema->createTable('agent_hot_prompt_state');
            $hotPrompt->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $hotPrompt->addColumn('run_id', Types::BIGINT);
            $hotPrompt->addColumn('last_seq', Types::INTEGER, ['default' => 0]);
            $hotPrompt->addColumn('token_estimate', Types::INTEGER, ['notnull' => false]);
            $hotPrompt->addColumn('context_compressed', Types::TEXT);
            $hotPrompt->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $hotPrompt->setPrimaryKey(['id']);
            $hotPrompt->addUniqueIndex(['run_id'], 'uniq_agent_hot_prompt_state_run_id');
            $hotPrompt->addForeignKeyConstraint('agent_runs', ['run_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_agent_hot_prompt_state_run');
        }

        if (!$schema->hasTable('agent_turn_index')) {
            $turnIndex = $schema->createTable('agent_turn_index');
            $turnIndex->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $turnIndex->addColumn('run_id', Types::BIGINT);
            $turnIndex->addColumn('turn_no', Types::INTEGER);
            $turnIndex->addColumn('assistant_stop_reason', Types::STRING, ['length' => 64, 'notnull' => false]);
            $turnIndex->addColumn('tool_calls_count', Types::INTEGER, ['default' => 0]);
            $turnIndex->addColumn('usage_json', Types::JSON, ['notnull' => false]);
            $turnIndex->addColumn('started_at', Types::DATETIME_IMMUTABLE);
            $turnIndex->addColumn('ended_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $turnIndex->setPrimaryKey(['id']);
            $turnIndex->addIndex(['run_id', 'turn_no'], 'idx_agent_turn_index_run_turn');
            $turnIndex->addForeignKeyConstraint('agent_runs', ['run_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_agent_turn_index_run');
        }

        if (!$schema->hasTable('agent_run_events')) {
            $events = $schema->createTable('agent_run_events');
            $events->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $events->addColumn('run_id', Types::BIGINT);
            $events->addColumn('seq', Types::INTEGER);
            $events->addColumn('turn_no', Types::INTEGER);
            $events->addColumn('type', Types::STRING, ['length' => 191]);
            $events->addColumn('payload_json', Types::JSON);
            $events->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $events->setPrimaryKey(['id']);
            $events->addUniqueIndex(['run_id', 'seq'], 'uniq_agent_run_events_run_seq');
            $events->addIndex(['run_id', 'created_at'], 'idx_agent_run_events_run_created');
            $events->addForeignKeyConstraint('agent_runs', ['run_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_agent_run_events_run');
        }

        if (!$schema->hasTable('agent_outbox')) {
            $outbox = $schema->createTable('agent_outbox');
            $outbox->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $outbox->addColumn('run_id', Types::BIGINT);
            $outbox->addColumn('seq', Types::INTEGER);
            $outbox->addColumn('sink', Types::STRING, ['length' => 16]);
            $outbox->addColumn('payload_json', Types::JSON);
            $outbox->addColumn('status', Types::STRING, ['length' => 16]);
            $outbox->addColumn('attempts', Types::INTEGER, ['default' => 0]);
            $outbox->addColumn('available_at', Types::DATETIME_IMMUTABLE);
            $outbox->addColumn('processed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $outbox->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $outbox->setPrimaryKey(['id']);
            $outbox->addUniqueIndex(['sink', 'run_id', 'seq'], 'uniq_agent_outbox_sink_run_seq');
            $outbox->addIndex(['sink', 'status', 'available_at'], 'idx_agent_outbox_pending');
            $outbox->addForeignKeyConstraint('agent_runs', ['run_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_agent_outbox_run');
        }

        if (!$schema->hasTable('agent_tool_jobs')) {
            $toolJobs = $schema->createTable('agent_tool_jobs');
            $toolJobs->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $toolJobs->addColumn('run_id', Types::BIGINT);
            $toolJobs->addColumn('turn_no', Types::INTEGER);
            $toolJobs->addColumn('step_id', Types::STRING, ['length' => 191]);
            $toolJobs->addColumn('tool_call_id', Types::STRING, ['length' => 191]);
            $toolJobs->addColumn('tool_name', Types::STRING, ['length' => 191]);
            $toolJobs->addColumn('order_index', Types::INTEGER);
            $toolJobs->addColumn('mode', Types::STRING, ['length' => 16]);
            $toolJobs->addColumn('tool_idempotency_key', Types::STRING, ['length' => 191, 'notnull' => false]);
            $toolJobs->addColumn('status', Types::STRING, ['length' => 32]);
            $toolJobs->addColumn('attempt', Types::INTEGER, ['default' => 1]);
            $toolJobs->addColumn('result_ref', Types::STRING, ['length' => 512, 'notnull' => false]);
            $toolJobs->addColumn('external_request_id', Types::STRING, ['length' => 191, 'notnull' => false]);
            $toolJobs->addColumn('error_json', Types::JSON, ['notnull' => false]);
            $toolJobs->addColumn('started_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $toolJobs->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $toolJobs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $toolJobs->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $toolJobs->setPrimaryKey(['id']);
            $toolJobs->addUniqueIndex(['run_id', 'tool_call_id'], 'uniq_agent_tool_jobs_run_tool_call');
            $toolJobs->addIndex(['run_id', 'status', 'created_at'], 'idx_agent_tool_jobs_run_status_created');
            $toolJobs->addIndex(['tool_name', 'tool_idempotency_key'], 'idx_agent_tool_jobs_tool_idempotency');
            $toolJobs->addForeignKeyConstraint('agent_runs', ['run_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_agent_tool_jobs_run');
        }
    }

    /**
     * Reverts the database schema changes applied by the up method.
     */
    public function down(Schema $schema): void
    {
        foreach (['agent_tool_jobs', 'agent_outbox', 'agent_run_events', 'agent_turn_index', 'agent_hot_prompt_state', 'agent_commands', 'agent_runs'] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }
    }
}
