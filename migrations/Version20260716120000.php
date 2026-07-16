<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add durable artifact_kind to deferred_subagent_child (backfill subagent, enforce NOT NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE deferred_subagent_child ADD COLUMN artifact_kind VARCHAR(32) DEFAULT 'subagent' NOT NULL");
        $this->addSql("UPDATE deferred_subagent_child SET artifact_kind = 'subagent' WHERE artifact_kind IS NULL OR artifact_kind = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_subagent_child DROP COLUMN artifact_kind');
    }
}
