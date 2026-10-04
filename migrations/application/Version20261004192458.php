<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\UuidV7;

final class Version20261004192458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist UUIDv7 provider cache keys for child runs and rebuild cache usage projections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_subagent_child ADD COLUMN provider_cache_key VARCHAR(36) DEFAULT NULL');
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM deferred_subagent_child') as $id) {
            $this->addSql('UPDATE deferred_subagent_child SET provider_cache_key = ? WHERE id = ?', [UuidV7::v7()->toRfc4122(), $id]);
        }

        // Old projections have input totals but no cached-token totals. Replay
        // canonical child events on recovery/resume instead of showing a partial ratio.
        $this->addSql('UPDATE deferred_subagent_child SET child_event_cursor = 0, child_lifecycle_projection = NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS SELECT id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, launch_model, launch_reasoning, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at FROM deferred_subagent_child');
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('CREATE TABLE deferred_subagent_child (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, batch_lifecycle_id VARCHAR(36) NOT NULL, batch_index INTEGER NOT NULL, child_run_id VARCHAR(36) NOT NULL, artifact_id VARCHAR(64) NOT NULL, agent_name VARCHAR(255) NOT NULL, task CLOB NOT NULL, launch_model VARCHAR(255) NOT NULL, launch_reasoning VARCHAR(64) NOT NULL, launch_status VARCHAR(32) NOT NULL, child_event_cursor INTEGER NOT NULL, child_lifecycle_projection CLOB DEFAULT NULL, projection_version INTEGER DEFAULT 1 NOT NULL, started_at DATETIME DEFAULT NULL, terminal_completed_at DATETIME DEFAULT NULL, terminal_status VARCHAR(32) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO deferred_subagent_child (id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, launch_model, launch_reasoning, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at) SELECT id, batch_lifecycle_id, batch_index, child_run_id, artifact_id, agent_name, task, launch_model, launch_reasoning, launch_status, child_event_cursor, child_lifecycle_projection, projection_version, started_at, terminal_completed_at, terminal_status, created_at, updated_at FROM __temp__deferred_subagent_child');
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql('CREATE INDEX idx_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)');
    }
}
