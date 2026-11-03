<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261102143815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS SELECT id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at, launch_model, launch_reasoning, provider_cache_key FROM deferred_subagent_child');
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('CREATE TABLE deferred_subagent_child (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, batch_lifecycle_id VARCHAR(36) NOT NULL, batch_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, child_lifecycle_projection CLOB DEFAULT NULL, projection_version INTEGER DEFAULT 1 NOT NULL, started_at DATETIME DEFAULT NULL, terminal_completed_at DATETIME DEFAULT NULL, terminal_status VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, launch_model VARCHAR(255) NOT NULL, launch_reasoning VARCHAR(64) NOT NULL, provider_cache_key VARCHAR(36) DEFAULT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_child (id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at, launch_model, launch_reasoning, provider_cache_key) SELECT id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at, launch_model, launch_reasoning, provider_cache_key FROM __temp__deferred_subagent_child');
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql('CREATE INDEX idx_child_active ON deferred_subagent_child (terminal_status, terminal_completed_at, launch_status, batch_lifecycle_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__tool_deferred_completion AS SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, created_at, updated_at, ready_at, backgrounded_at, interruption_reason, continuation_required, accepted_result_reference_json FROM tool_deferred_completion');
        $this->addSql('DROP TABLE tool_deferred_completion');
        $this->addSql('CREATE TABLE tool_deferred_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, body_reference_json VARCHAR(16384) NOT NULL, order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL, mode VARCHAR(32) DEFAULT NULL, timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, tools_ref VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ready_at DATETIME DEFAULT NULL, backgrounded_at DATETIME DEFAULT NULL, interruption_reason VARCHAR(32) DEFAULT NULL, continuation_required BOOLEAN DEFAULT 0 NOT NULL, accepted_result_reference_json VARCHAR(16384) DEFAULT NULL)');
        $this->addSql('INSERT INTO tool_deferred_completion (id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, created_at, updated_at, ready_at, backgrounded_at, interruption_reason, continuation_required, accepted_result_reference_json) SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, created_at, updated_at, ready_at, backgrounded_at, interruption_reason, continuation_required, accepted_result_reference_json FROM __temp__tool_deferred_completion');
        $this->addSql('DROP TABLE __temp__tool_deferred_completion');
        $this->addSql('CREATE INDEX idx_deferred_pending ON tool_deferred_completion (run_id, status, turn_no, step_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_deferred_id ON tool_deferred_completion (deferred_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_run_tool_call ON tool_deferred_completion (run_id, tool_call_id)');
        $this->addSql('CREATE INDEX idx_deferred_active_tool ON tool_deferred_completion (tool_name, status, run_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS SELECT id, batch_lifecycle_id, batch_index, child_run_id, provider_cache_key, artifact_id, agent_name, task, launch_model, launch_reasoning, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at FROM deferred_subagent_child');
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('CREATE TABLE deferred_subagent_child (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, batch_lifecycle_id VARCHAR(36) NOT NULL, batch_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, provider_cache_key VARCHAR(36) DEFAULT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, launch_model VARCHAR(255) NOT NULL, launch_reasoning VARCHAR(64) NOT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, child_lifecycle_projection CLOB DEFAULT NULL, projection_version INTEGER DEFAULT 1 NOT NULL, started_at DATETIME DEFAULT NULL, terminal_completed_at DATETIME DEFAULT NULL, terminal_status VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_child (id, batch_lifecycle_id, batch_index, child_run_id, provider_cache_key, artifact_id, agent_name, task, launch_model, launch_reasoning, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at) SELECT id, batch_lifecycle_id, batch_index, child_run_id, provider_cache_key, artifact_id, agent_name, task, launch_model, launch_reasoning, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at FROM __temp__deferred_subagent_child');
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__tool_deferred_completion AS SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, ready_at, backgrounded_at, interruption_reason, continuation_required, accepted_result_reference_json, created_at, updated_at FROM tool_deferred_completion');
        $this->addSql('DROP TABLE tool_deferred_completion');
        $this->addSql('CREATE TABLE tool_deferred_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, body_reference_json VARCHAR(16384) NOT NULL, order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL, mode VARCHAR(32) DEFAULT NULL, timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, tools_ref VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, ready_at DATETIME DEFAULT NULL, backgrounded_at DATETIME DEFAULT NULL, interruption_reason VARCHAR(32) DEFAULT NULL, continuation_required BOOLEAN DEFAULT 0 NOT NULL, accepted_result_reference_json VARCHAR(16384) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO tool_deferred_completion (id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, ready_at, backgrounded_at, interruption_reason, continuation_required, accepted_result_reference_json, created_at, updated_at) SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, ready_at, backgrounded_at, interruption_reason, continuation_required, accepted_result_reference_json, created_at, updated_at FROM __temp__tool_deferred_completion');
        $this->addSql('DROP TABLE __temp__tool_deferred_completion');
        $this->addSql('CREATE INDEX idx_deferred_pending ON tool_deferred_completion (run_id, status, turn_no, step_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_deferred_id ON tool_deferred_completion (deferred_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_run_tool_call ON tool_deferred_completion (run_id, tool_call_id)');
    }
}
