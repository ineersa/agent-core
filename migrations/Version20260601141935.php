<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601141935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cwd VARCHAR(255) NOT NULL, prompt VARCHAR(255) DEFAULT NULL, parent_id VARCHAR(255) DEFAULT NULL, root_id VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, model_provider VARCHAR(255) DEFAULT NULL, model_name VARCHAR(255) DEFAULT NULL, reasoning VARCHAR(255) DEFAULT NULL, created_at VARCHAR(255) NOT NULL, updated_at VARCHAR(255) NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE hatfield_session');
    }
}
