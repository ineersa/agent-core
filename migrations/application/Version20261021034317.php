<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261021034317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE background_completion_receipt (identity VARCHAR(255) NOT NULL, source_run_id VARCHAR(255) NOT NULL, accepted_destination_run_id VARCHAR(255) NOT NULL, destination_run_id VARCHAR(255) NOT NULL, root_run_id VARCHAR(255) NOT NULL, text CLOB NOT NULL, forwarded_text CLOB NOT NULL, disposition VARCHAR(32) NOT NULL, PRIMARY KEY (identity))');
        $this->addSql('CREATE INDEX background_completion_forwarding ON background_completion_receipt (root_run_id, disposition)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_batch AS SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at, parent_model FROM deferred_subagent_batch');
        $this->addSql('DROP TABLE deferred_subagent_batch');
        $this->addSql('CREATE TABLE deferred_subagent_batch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, launch_status VARCHAR(32) NOT NULL, aggregate_progress_revision INTEGER NOT NULL, delivered_progress_revision INTEGER NOT NULL, terminal_completion_enqueued_at DATETIME DEFAULT NULL, projection_version INTEGER DEFAULT 1 NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, interruption_kind VARCHAR(32) DEFAULT NULL, interruption_requested_at DATETIME DEFAULT NULL, interruption_progress_enqueued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, parent_model VARCHAR(255) DEFAULT NULL, backgrounded_at DATETIME DEFAULT NULL, completion_source CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_batch (id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at, parent_model) SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at, parent_model FROM __temp__deferred_subagent_batch');
        $this->addSql('DROP TABLE __temp__deferred_subagent_batch');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_lifecycle ON deferred_subagent_batch (lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_parent_tool ON deferred_subagent_batch (parent_run_id, parent_tool_call_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE background_completion_receipt');
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_batch AS SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, parent_model, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at FROM deferred_subagent_batch');
        $this->addSql('DROP TABLE deferred_subagent_batch');
        $this->addSql('CREATE TABLE deferred_subagent_batch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, parent_model VARCHAR(255) DEFAULT NULL, launch_status VARCHAR(32) NOT NULL, aggregate_progress_revision INTEGER NOT NULL, delivered_progress_revision INTEGER NOT NULL, terminal_completion_enqueued_at DATETIME DEFAULT NULL, projection_version INTEGER DEFAULT 1 NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, interruption_kind VARCHAR(32) DEFAULT NULL, interruption_requested_at DATETIME DEFAULT NULL, interruption_progress_enqueued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, execution_mode VARCHAR(16) NOT NULL, total_child_count INTEGER NOT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_batch (id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, parent_model, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at) SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, parent_model, launch_status, aggregate_progress_revision, delivered_progress_revision, terminal_completion_enqueued_at, projection_version, started_at, deadline_at, interruption_kind, interruption_requested_at, interruption_progress_enqueued_at, created_at, updated_at FROM __temp__deferred_subagent_batch');
        $this->addSql('DROP TABLE __temp__deferred_subagent_batch');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_lifecycle ON deferred_subagent_batch (lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_batch_parent_tool ON deferred_subagent_batch (parent_run_id, parent_tool_call_id)');
    }
}
