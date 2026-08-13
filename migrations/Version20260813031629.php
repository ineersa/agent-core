<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: replace deferred_subagent_child.definition_model
 * with concrete launch_model / launch_reasoning (from entity metadata diff).
 *
 * Generated via:
 *   HATFIELD_CWD=<db-with-schema-through-Version20260723230000> \
 *   APP_ENV=dev php bin/console doctrine:migrations:diff --no-interaction --formatted
 *
 * Unrelated table rebuild noise from the full diff was discarded; only the
 * deferred_subagent_child column rename semantics from the generator are kept.
 * Active development: existing rows without launch identity fail at INSERT.
 */
final class Version20260813031629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace deferred_subagent_child.definition_model with non-null launch_model and launch_reasoning';
    }

    public function up(Schema $schema): void
    {
        // Startup executor tests may mark earlier versions applied without creating
        // the deferred child table. Only rewrite when the table exists.
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['deferred_subagent_child'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('deferred_subagent_child');
        foreach ($columns as $column) {
            if ('launch_model' === $column->getName()) {
                return;
            }
        }

        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS
            SELECT
              id,
              batch_lifecycle_id,
              batch_index,
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
              updated_at
            FROM
              deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql(<<<'SQL'
            CREATE TABLE deferred_subagent_child (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              batch_lifecycle_id VARCHAR(36) NOT NULL,
              batch_index INTEGER NOT NULL,
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
              launch_reasoning VARCHAR(64) NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO deferred_subagent_child (
              id, batch_lifecycle_id, batch_index,
              child_run_id, artifact_id, agent_name,
              task, launch_status, child_event_cursor,
              child_lifecycle_projection, projection_version,
              started_at, terminal_completed_at,
              terminal_status, created_at, updated_at
            )
            SELECT
              id,
              batch_lifecycle_id,
              batch_index,
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
              updated_at
            FROM
              __temp__deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql('CREATE INDEX idx_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['deferred_subagent_child'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('deferred_subagent_child');
        foreach ($columns as $column) {
            if ('definition_model' === $column->getName()) {
                return;
            }
        }

        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__deferred_subagent_child AS
            SELECT
              id,
              batch_lifecycle_id,
              batch_index,
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
              updated_at
            FROM
              deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql(<<<'SQL'
            CREATE TABLE deferred_subagent_child (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              batch_lifecycle_id VARCHAR(36) NOT NULL,
              batch_index INTEGER NOT NULL,
              child_run_id VARCHAR(36) NOT NULL,
              artifact_id VARCHAR(64) NOT NULL,
              agent_name VARCHAR(255) NOT NULL,
              task CLOB NOT NULL,
              launch_status VARCHAR(32) NOT NULL,
              child_event_cursor INTEGER NOT NULL,
              child_lifecycle_projection CLOB DEFAULT NULL,
              projection_version INTEGER NOT NULL,
              started_at DATETIME DEFAULT NULL,
              terminal_completed_at DATETIME DEFAULT NULL,
              terminal_status VARCHAR(32) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              definition_model VARCHAR(255) DEFAULT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO deferred_subagent_child (
              id, batch_lifecycle_id, batch_index,
              child_run_id, artifact_id, agent_name,
              task, launch_status, child_event_cursor,
              child_lifecycle_projection, projection_version,
              started_at, terminal_completed_at,
              terminal_status, created_at, updated_at
            )
            SELECT
              id,
              batch_lifecycle_id,
              batch_index,
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
              updated_at
            FROM
              __temp__deferred_subagent_child
        SQL);
        $this->addSql('DROP TABLE __temp__deferred_subagent_child');
        $this->addSql('CREATE INDEX idx_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)
        SQL);
    }
}
