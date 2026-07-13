<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add child_lifecycle_projection to deferred_single_subagent_launch (Piece 3B1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_single_subagent_launch ADD COLUMN child_lifecycle_projection CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_single_subagent_launch AS SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, child_run_id, artifact_id, agent_name, task, definition_model, launch_status, child_event_cursor, started_at, deadline_at, created_at, updated_at FROM deferred_single_subagent_launch');
        $this->addSql('DROP TABLE deferred_single_subagent_launch');
        $this->addSql('CREATE TABLE deferred_single_subagent_launch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, definition_model VARCHAR(255) DEFAULT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_single_subagent_launch SELECT id, lifecycle_id, parent_run_id, parent_turn_no, parent_tool_call_id, parent_order_index, child_run_id, artifact_id, agent_name, task, definition_model, launch_status, child_event_cursor, started_at, deadline_at, created_at, updated_at FROM __temp__deferred_single_subagent_launch');
        $this->addSql('DROP TABLE __temp__deferred_single_subagent_launch');
    }
}
