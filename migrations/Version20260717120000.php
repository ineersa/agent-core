<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fork deferred pre-launch compaction staging on deferred_subagent_batch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_subagent_batch ADD COLUMN fork_local_run_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE deferred_subagent_batch ADD COLUMN prelaunch_phase VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __deferred_subagent_batch AS SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, execution_mode, total_child_count, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at FROM deferred_subagent_batch');
        $this->addSql('DROP TABLE deferred_subagent_batch');
        $this->addSql('CREATE TABLE deferred_subagent_batch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, execution_mode VARCHAR(16) NOT NULL, total_child_count INTEGER NOT NULL, launch_status VARCHAR(32) NOT NULL, aggregate_progress_revision INTEGER NOT NULL, delivered_progress_revision INTEGER NOT NULL, terminal_completion_enqueued_at DATETIME DEFAULT NULL, projection_version INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, interruption_kind VARCHAR(32) DEFAULT NULL, interruption_requested_at DATETIME DEFAULT NULL, interruption_progress_enqueued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_batch SELECT * FROM __deferred_subagent_batch');
    }
}
