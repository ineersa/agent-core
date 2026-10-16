<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016205256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accepted terminal completion identity columns to deferred_tool_completion';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_tool_completion ADD COLUMN accepted_completion_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE deferred_tool_completion ADD COLUMN accepted_result_fingerprint VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_tool_completion AS SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, arguments, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, assistant_message, arg_schema, tools_ref, invocation_id, controller_run_id, execution_owner_kind, execution_owner_reference_json, original_execution_mode, deadline_metadata_json, origin_wake_generation, root_wake_generation, envelope_fingerprint, caller_disposition, execution_observation, status, created_at, updated_at FROM deferred_tool_completion');
        $this->addSql('DROP TABLE deferred_tool_completion');
        $this->addSql('CREATE TABLE deferred_tool_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, arguments CLOB NOT NULL, order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL, mode VARCHAR(32) DEFAULT NULL, timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, assistant_message CLOB DEFAULT NULL, arg_schema CLOB DEFAULT NULL, tools_ref VARCHAR(255) DEFAULT NULL, invocation_id VARCHAR(64) DEFAULT NULL, controller_run_id VARCHAR(255) DEFAULT NULL, execution_owner_kind VARCHAR(64) DEFAULT NULL, execution_owner_reference_json CLOB DEFAULT NULL, original_execution_mode VARCHAR(32) DEFAULT NULL, deadline_metadata_json CLOB DEFAULT NULL, origin_wake_generation INTEGER DEFAULT NULL, root_wake_generation INTEGER DEFAULT NULL, envelope_fingerprint VARCHAR(64) DEFAULT NULL, caller_disposition VARCHAR(64) NOT NULL DEFAULT \'foreground_pending\', execution_observation VARCHAR(64) NOT NULL DEFAULT \'active\', status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_tool_completion (id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, arguments, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, assistant_message, arg_schema, tools_ref, invocation_id, controller_run_id, execution_owner_kind, execution_owner_reference_json, original_execution_mode, deadline_metadata_json, origin_wake_generation, root_wake_generation, envelope_fingerprint, caller_disposition, execution_observation, status, created_at, updated_at) SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, arguments, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, assistant_message, arg_schema, tools_ref, invocation_id, controller_run_id, execution_owner_kind, execution_owner_reference_json, original_execution_mode, deadline_metadata_json, origin_wake_generation, root_wake_generation, envelope_fingerprint, caller_disposition, execution_observation, status, created_at, updated_at FROM __temp__deferred_tool_completion');
        $this->addSql('DROP TABLE __temp__deferred_tool_completion');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_tool_completion_deferred_id ON deferred_tool_completion (deferred_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_tool_completion_run_tool_call ON deferred_tool_completion (run_id, tool_call_id)');
    }
}
