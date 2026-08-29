<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add backgrounded_at and completion_notified_at to background_process table for background completion notifications';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE background_process ADD COLUMN backgrounded_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE background_process ADD COLUMN completion_notified_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE background_process DROP COLUMN backgrounded_at');
        $this->addSql('ALTER TABLE background_process DROP COLUMN completion_notified_at');
    }
}
