<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused wake counters superseded by immutable completion delivery fences.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_tool_completion DROP COLUMN origin_wake_generation');
        $this->addSql('ALTER TABLE deferred_tool_completion DROP COLUMN root_wake_generation');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deferred_tool_completion ADD COLUMN origin_wake_generation INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE deferred_tool_completion ADD COLUMN root_wake_generation INTEGER DEFAULT NULL');
    }
}
