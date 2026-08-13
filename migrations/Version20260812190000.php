<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace deferred child definition_model with concrete launch identity columns.
 */
final class Version20260812190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace deferred_subagent_child.definition_model with launch_model and launch_reasoning';
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

        if (isset($names['definition_model'])) {
            $this->addSql('ALTER TABLE deferred_subagent_child DROP COLUMN definition_model');
        }
        if (!isset($names['launch_model'])) {
            $this->addSql("ALTER TABLE deferred_subagent_child ADD launch_model VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!isset($names['launch_reasoning'])) {
            $this->addSql("ALTER TABLE deferred_subagent_child ADD launch_reasoning VARCHAR(64) NOT NULL DEFAULT ''");
        }
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

        if (isset($names['launch_reasoning'])) {
            $this->addSql('ALTER TABLE deferred_subagent_child DROP COLUMN launch_reasoning');
        }
        if (isset($names['launch_model'])) {
            $this->addSql('ALTER TABLE deferred_subagent_child DROP COLUMN launch_model');
        }
        if (!isset($names['definition_model'])) {
            $this->addSql('ALTER TABLE deferred_subagent_child ADD definition_model VARCHAR(255) DEFAULT NULL');
        }
    }
}
