<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the active session reasoning baseline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hatfield_session ADD COLUMN reasoning_baseline CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hatfield_session DROP COLUMN reasoning_baseline');
    }
}
