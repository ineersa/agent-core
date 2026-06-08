<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608162000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mandatory session display name column to hatfield_session';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE hatfield_session ADD COLUMN name VARCHAR(200) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hatfield_session DROP COLUMN name');
    }
}
