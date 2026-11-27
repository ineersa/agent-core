<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261127020326 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS
            SELECT
              id,
              batch_lifecycle_id,
              child_run_id,
              artifact_id,
              agent_name,
              task,
              launch_status,
              child_event_cursor,
              child_lifecycle_projection,
              projection_version,
              started_at,
              terminal_completed_at,
              terminal_status,
              created_at,
              updated_at,
              launch_model,
              launch_reasoning,
              provider_cache_key
            FROM
              deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql(<<<'SQL'
            CREATE TABLE deferred_subagent_child (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              batch_lifecycle_id VARCHAR(36) NOT NULL,
              child_run_id VARCHAR(36) NOT NULL,
              artifact_id VARCHAR(64) NOT NULL,
              agent_name VARCHAR(255) NOT NULL,
              task CLOB NOT NULL,
              launch_status VARCHAR(32) NOT NULL,
              child_event_cursor INTEGER NOT NULL,
              child_lifecycle_projection CLOB DEFAULT NULL,
              projection_version INTEGER DEFAULT 1 NOT NULL,
              started_at DATETIME DEFAULT NULL,
              terminal_completed_at DATETIME DEFAULT NULL,
              terminal_status VARCHAR(32) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              launch_model VARCHAR(255) NOT NULL,
              launch_reasoning VARCHAR(64) NOT NULL,
              provider_cache_key VARCHAR(36) DEFAULT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO deferred_subagent_child (
              id, batch_lifecycle_id, child_run_id,
              artifact_id, agent_name, task, launch_status,
              child_event_cursor, child_lifecycle_projection,
              projection_version, started_at,
              terminal_completed_at, terminal_status,
              created_at, updated_at, launch_model,
              launch_reasoning, provider_cache_key
            )
            SELECT
              id,
              batch_lifecycle_id,
              child_run_id,
              artifact_id,
              agent_name,
              task,
              launch_status,
              child_event_cursor,
              child_lifecycle_projection,
              projection_version,
              started_at,
              terminal_completed_at,
              terminal_status,
              created_at,
              updated_at,
              launch_model,
              launch_reasoning,
              provider_cache_key
            FROM
              __temp__deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_child_active ON deferred_subagent_child (
              terminal_status, terminal_completed_at,
              launch_status, batch_lifecycle_id
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS
            SELECT
              id,
              batch_lifecycle_id,
              child_run_id,
              provider_cache_key,
              artifact_id,
              agent_name,
              task,
              launch_model,
              launch_reasoning,
              launch_status,
              child_event_cursor,
              child_lifecycle_projection,
              projection_version,
              started_at,
              terminal_completed_at,
              terminal_status,
              created_at,
              updated_at
            FROM
              deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql(<<<'SQL'
            CREATE TABLE deferred_subagent_child (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              batch_lifecycle_id VARCHAR(36) NOT NULL,
              child_run_id VARCHAR(36) NOT NULL,
              provider_cache_key VARCHAR(36) DEFAULT NULL,
              artifact_id VARCHAR(64) NOT NULL,
              agent_name VARCHAR(255) NOT NULL,
              task CLOB NOT NULL,
              launch_model VARCHAR(255) NOT NULL,
              launch_reasoning VARCHAR(64) NOT NULL,
              launch_status VARCHAR(32) NOT NULL,
              child_event_cursor INTEGER NOT NULL,
              child_lifecycle_projection CLOB DEFAULT NULL,
              projection_version INTEGER DEFAULT 1 NOT NULL,
              started_at DATETIME DEFAULT NULL,
              terminal_completed_at DATETIME DEFAULT NULL,
              terminal_status VARCHAR(32) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              batch_index INTEGER NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO deferred_subagent_child (
              id, batch_lifecycle_id, child_run_id,
              provider_cache_key, artifact_id,
              agent_name, task, launch_model, launch_reasoning,
              launch_status, child_event_cursor,
              child_lifecycle_projection, projection_version,
              started_at, terminal_completed_at,
              terminal_status, created_at, updated_at
            )
            SELECT
              id,
              batch_lifecycle_id,
              child_run_id,
              provider_cache_key,
              artifact_id,
              agent_name,
              task,
              launch_model,
              launch_reasoning,
              launch_status,
              child_event_cursor,
              child_lifecycle_projection,
              projection_version,
              started_at,
              terminal_completed_at,
              terminal_status,
              created_at,
              updated_at
            FROM
              __temp__deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_child_active ON deferred_subagent_child (
              terminal_status, terminal_completed_at,
              launch_status, batch_lifecycle_id
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)
        SQL);
    }
}
