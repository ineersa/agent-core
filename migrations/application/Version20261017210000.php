<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist controller restart generations and detached completion delivery fences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hatfield_session ADD COLUMN controller_generation INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE deferred_tool_completion ADD COLUMN delivery_fence_json CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_tool_completion DROP COLUMN delivery_fence_json');
        $this->addSql('ALTER TABLE hatfield_session DROP COLUMN controller_generation');
    }
}
