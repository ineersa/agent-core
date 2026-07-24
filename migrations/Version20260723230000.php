<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260723230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deferred_subagent_batch.parent_model for durable child model inheritance';
    }

    public function up(Schema $schema): void
    {
        // Startup executor tests may mark earlier versions applied without creating
        // the deferred batch table. Only alter existing tables.
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['deferred_subagent_batch'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('deferred_subagent_batch');
        foreach ($columns as $column) {
            if ('parent_model' === $column->getName()) {
                return;
            }
        }

        $this->addSql('ALTER TABLE deferred_subagent_batch ADD parent_model VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_subagent_batch DROP parent_model');
    }
}
