<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist optional reasoning_override on deferred_subagent_child.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_subagent_child ADD COLUMN reasoning_override VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __deferred_subagent_child AS SELECT id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, definition_model, artifact_kind, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at FROM deferred_subagent_child');
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('CREATE TABLE deferred_subagent_child (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, batch_lifecycle_id VARCHAR(36) NOT NULL, batch_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, definition_model VARCHAR(255) DEFAULT NULL, artifact_kind VARCHAR(32) NOT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, child_lifecycle_projection CLOB DEFAULT NULL, projection_version INTEGER NOT NULL, started_at DATETIME DEFAULT NULL, terminal_completed_at DATETIME DEFAULT NULL, terminal_status VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_child SELECT * FROM __deferred_subagent_child');
    }
}
