<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add normalized deferred_subagent_batch and deferred_subagent_child (Piece 4A)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deferred_subagent_batch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, execution_mode VARCHAR(16) NOT NULL, total_child_count INTEGER NOT NULL, launch_status VARCHAR(32) NOT NULL, aggregate_progress_revision INTEGER NOT NULL, delivered_progress_revision INTEGER NOT NULL, terminal_completion_enqueued_at DATETIME DEFAULT NULL, projection_version INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, interruption_kind VARCHAR(32) DEFAULT NULL, interruption_requested_at DATETIME DEFAULT NULL, interruption_progress_enqueued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_lifecycle ON deferred_subagent_batch (lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_parent_tool ON deferred_subagent_batch (parent_run_id, parent_tool_call_id)');

        $this->addSql('CREATE TABLE deferred_subagent_child (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, batch_lifecycle_id VARCHAR(36) NOT NULL, batch_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, definition_model VARCHAR(255) DEFAULT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, child_lifecycle_projection CLOB DEFAULT NULL, projection_version INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, terminal_completed_at DATETIME DEFAULT NULL, terminal_status VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)');
        $this->addSql('CREATE INDEX idx_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('DROP TABLE deferred_subagent_batch');
    }
}
