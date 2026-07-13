<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deferred_single_subagent_launch projection for Piece 3A idempotent single-child deferred launch';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deferred_single_subagent_launch (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lifecycle_id VARCHAR(36) NOT NULL, parent_run_id VARCHAR(255) NOT NULL, parent_turn_no INTEGER NOT NULL, parent_tool_call_id VARCHAR(255) NOT NULL, parent_order_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, definition_model VARCHAR(255) DEFAULT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_single_subagent_lifecycle ON deferred_single_subagent_launch (lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_single_subagent_parent_tool ON deferred_single_subagent_launch (parent_run_id, parent_tool_call_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_single_subagent_child_run ON deferred_single_subagent_launch (child_run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE deferred_single_subagent_launch');
    }
}
