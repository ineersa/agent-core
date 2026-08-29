<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260828223857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE run_operational_human_input (question_id VARCHAR(255) NOT NULL, order_index INTEGER NOT NULL, continuation_kind VARCHAR(32) NOT NULL, tool_call_id VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, run_id VARCHAR(255) NOT NULL, PRIMARY KEY (run_id, question_id), CONSTRAINT FK_E0D89FA284E3FEC4 FOREIGN KEY (run_id) REFERENCES run_operational_state (run_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E0D89FA284E3FEC4 ON run_operational_human_input (run_id)');
        $this->addSql('CREATE INDEX idx_run_operational_human_current ON run_operational_human_input (run_id, status, order_index)');
        $this->addSql('CREATE TABLE run_operational_state (run_id VARCHAR(255) NOT NULL, parent_run_id VARCHAR(255) DEFAULT NULL, owner_session_id VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, turn_no INTEGER NOT NULL, active_step_id VARCHAR(255) DEFAULT NULL, operation_turn_no INTEGER DEFAULT NULL, operation_step_id VARCHAR(255) DEFAULT NULL, operation_attempt INTEGER DEFAULT NULL, operation_key VARCHAR(255) DEFAULT NULL, last_applied_advance_key VARCHAR(255) DEFAULT NULL, last_applied_compaction_key VARCHAR(255) DEFAULT NULL, retryable_failure BOOLEAN NOT NULL, retry_attempts INTEGER NOT NULL, last_event_sequence INTEGER NOT NULL, transition_version INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (run_id))');
        $this->addSql('CREATE INDEX idx_run_operational_state_owner ON run_operational_state (owner_session_id)');
        $this->addSql('CREATE INDEX idx_run_operational_state_status ON run_operational_state (status)');
        $this->addSql('CREATE TABLE run_operational_tool_call (batch_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, order_index INTEGER NOT NULL, status VARCHAR(32) NOT NULL, attempt INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, run_id VARCHAR(255) NOT NULL, PRIMARY KEY (run_id, batch_id, tool_call_id), CONSTRAINT FK_FC2305BB84E3FEC4 FOREIGN KEY (run_id) REFERENCES run_operational_state (run_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_FC2305BB84E3FEC4 ON run_operational_tool_call (run_id)');
        $this->addSql('CREATE INDEX idx_run_operational_tool_current ON run_operational_tool_call (run_id, batch_id, status, order_index)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_batch AS SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at, parent_model FROM deferred_subagent_batch');
        $this->addSql('DROP TABLE deferred_subagent_batch');
        $this->addSql('CREATE TABLE deferred_subagent_batch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, execution_mode VARCHAR(16) NOT NULL, total_child_count INTEGER NOT NULL, launch_status VARCHAR(32) NOT NULL, aggregate_progress_revision INTEGER NOT NULL, delivered_progress_revision INTEGER NOT NULL, terminal_completion_enqueued_at DATETIME DEFAULT NULL, projection_version INTEGER DEFAULT 1 NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, interruption_kind VARCHAR(32) DEFAULT NULL, interruption_requested_at DATETIME DEFAULT NULL, interruption_progress_enqueued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_model VARCHAR(255) DEFAULT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_batch (id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at, parent_model) SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at, parent_model FROM __temp__deferred_subagent_batch');
        $this->addSql('DROP TABLE __temp__deferred_subagent_batch');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_parent_tool ON deferred_subagent_batch (parent_run_id, parent_tool_call_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_lifecycle ON deferred_subagent_batch (lifecycle_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__cache_items AS SELECT item_id, item_data, item_lifetime, item_time FROM cache_items');
        $this->addSql('DROP TABLE cache_items');
        $this->addSql('CREATE TABLE cache_items (item_id CLOB NOT NULL, item_data BLOB NOT NULL, item_lifetime INTEGER UNSIGNED DEFAULT NULL, item_time INTEGER UNSIGNED NOT NULL, PRIMARY KEY (item_id))');
        $this->addSql('INSERT INTO cache_items (item_id, item_data, item_lifetime, item_time) SELECT item_id, item_data, item_lifetime, item_time FROM __temp__cache_items');
        $this->addSql('DROP TABLE __temp__cache_items');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE run_operational_human_input');
        $this->addSql('DROP TABLE run_operational_state');
        $this->addSql('DROP TABLE run_operational_tool_call');
        $this->addSql('CREATE TEMPORARY TABLE __temp__cache_items AS SELECT item_id, item_data, item_lifetime, item_time FROM cache_items');
        $this->addSql('DROP TABLE cache_items');
        $this->addSql('CREATE TABLE cache_items (item_id CLOB NOT NULL, item_data BLOB NOT NULL, item_lifetime INTEGER DEFAULT NULL, item_time INTEGER NOT NULL, PRIMARY KEY (item_id))');
        $this->addSql('INSERT INTO cache_items (item_id, item_data, item_lifetime, item_time) SELECT item_id, item_data, item_lifetime, item_time FROM __temp__cache_items');
        $this->addSql('DROP TABLE __temp__cache_items');
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_batch AS SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, parent_model, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at FROM deferred_subagent_batch');
        $this->addSql('DROP TABLE deferred_subagent_batch');
        $this->addSql('CREATE TABLE deferred_subagent_batch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, parent_model VARCHAR(255) DEFAULT NULL, execution_mode VARCHAR(16) NOT NULL, total_child_count INTEGER NOT NULL, launch_status VARCHAR(32) NOT NULL, aggregate_progress_revision INTEGER NOT NULL, delivered_progress_revision INTEGER NOT NULL, terminal_completion_enqueued_at DATETIME DEFAULT NULL, projection_version INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, interruption_kind VARCHAR(32) DEFAULT NULL, interruption_requested_at DATETIME DEFAULT NULL, interruption_progress_enqueued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_batch (id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, parent_model, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at) SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, parent_model, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at FROM __temp__deferred_subagent_batch');
        $this->addSql('DROP TABLE __temp__deferred_subagent_batch');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_lifecycle ON deferred_subagent_batch (lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_parent_tool ON deferred_subagent_batch (parent_run_id, parent_tool_call_id)');
    }
}
