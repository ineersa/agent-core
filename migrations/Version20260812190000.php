<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace deferred child definition_model with concrete launch identity columns.
 *
 * Existing rows cannot reconstruct reasoning from definition_model alone, so any
 * non-empty table fails closed rather than inventing empty/default identity.
 */
final class Version20260812190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace deferred_subagent_child.definition_model with non-empty launch_model and launch_reasoning';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['deferred_subagent_child'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('deferred_subagent_child');
        $names = [];
        foreach ($columns as $column) {
            $names[$column->getName()] = true;
        }

        $hasLaunchModel = isset($names['launch_model']);
        $hasLaunchReasoning = isset($names['launch_reasoning']);
        $hasDefinitionModel = isset($names['definition_model']);

        if ($hasLaunchModel && $hasLaunchReasoning && !$hasDefinitionModel) {
            $emptyIdentityCount = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM deferred_subagent_child WHERE launch_model = '' OR launch_reasoning = '' OR TRIM(launch_model) = '' OR TRIM(launch_reasoning) = ''",
            );
            if ($emptyIdentityCount > 0) {
                throw new \RuntimeException(
                    'deferred_subagent_child has rows with empty launch_model/launch_reasoning; refuse to keep empty durable child identity. Clear deferred children and retry.',
                );
            }

            return;
        }

        $rowCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM deferred_subagent_child');
        if ($rowCount > 0) {
            throw new \RuntimeException(
                'Cannot migrate deferred_subagent_child to launch_model/launch_reasoning while rows exist: reasoning cannot be reconstructed from definition_model. Clear deferred children and retry.',
            );
        }

        // SQLite cannot ADD NOT NULL columns without a DEFAULT when rewriting in place.
        // Empty-table rebuild yields real NOT NULL launch identity columns with no empty default.
        $this->addSql('CREATE TABLE deferred_subagent_child__launch_identity (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            batch_lifecycle_id VARCHAR(36) NOT NULL,
            batch_index INTEGER NOT NULL,
            child_run_id VARCHAR(36) NOT NULL,
            artifact_id VARCHAR(64) NOT NULL,
            agent_name VARCHAR(255) NOT NULL,
            task CLOB NOT NULL,
            launch_model VARCHAR(255) NOT NULL,
            launch_reasoning VARCHAR(64) NOT NULL,
            launch_status VARCHAR(32) NOT NULL,
            child_event_cursor INTEGER NOT NULL,
            child_lifecycle_projection CLOB DEFAULT NULL,
            projection_version INTEGER NOT NULL,
            started_at DATETIME DEFAULT NULL,
            terminal_completed_at DATETIME DEFAULT NULL,
            terminal_status VARCHAR(32) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('ALTER TABLE deferred_subagent_child__launch_identity RENAME TO deferred_subagent_child');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)');
        $this->addSql('CREATE INDEX idx_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)');
    }

    public function down(Schema $schema): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['deferred_subagent_child'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('deferred_subagent_child');
        $names = [];
        foreach ($columns as $column) {
            $names[$column->getName()] = true;
        }

        if (isset($names['definition_model']) && !isset($names['launch_model']) && !isset($names['launch_reasoning'])) {
            return;
        }

        $rowCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM deferred_subagent_child');
        if ($rowCount > 0) {
            throw new \RuntimeException(
                'Cannot reverse deferred_subagent_child launch identity migration while rows exist.',
            );
        }

        $this->addSql('CREATE TABLE deferred_subagent_child__definition_model (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            batch_lifecycle_id VARCHAR(36) NOT NULL,
            batch_index INTEGER NOT NULL,
            child_run_id VARCHAR(36) NOT NULL,
            artifact_id VARCHAR(64) NOT NULL,
            agent_name VARCHAR(255) NOT NULL,
            task CLOB NOT NULL,
            definition_model VARCHAR(255) DEFAULT NULL,
            launch_status VARCHAR(32) NOT NULL,
            child_event_cursor INTEGER NOT NULL,
            child_lifecycle_projection CLOB DEFAULT NULL,
            projection_version INTEGER NOT NULL,
            started_at DATETIME DEFAULT NULL,
            terminal_completed_at DATETIME DEFAULT NULL,
            terminal_status VARCHAR(32) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        $this->addSql('DROP TABLE deferred_subagent_child');
        $this->addSql('ALTER TABLE deferred_subagent_child__definition_model RENAME TO deferred_subagent_child');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_run ON deferred_subagent_child (child_run_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_subagent_child_batch_index ON deferred_subagent_child (batch_lifecycle_id, batch_index)');
        $this->addSql('CREATE INDEX idx_deferred_subagent_child_batch ON deferred_subagent_child (batch_lifecycle_id)');
    }
}
