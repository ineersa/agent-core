<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601031141 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__hatfield_session AS SELECT id, cwd, prompt, created_at, updated_at FROM hatfield_session');
        $this->addSql('DROP TABLE hatfield_session');
        $this->addSql('CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cwd VARCHAR(255) NOT NULL, prompt VARCHAR(255) DEFAULT NULL, created_at VARCHAR(255) NOT NULL, updated_at VARCHAR(255) NOT NULL, public_id VARCHAR(255) DEFAULT NULL, parent_id VARCHAR(255) DEFAULT NULL, root_id VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, model_provider VARCHAR(255) DEFAULT NULL, model_name VARCHAR(255) DEFAULT NULL, reasoning VARCHAR(255) DEFAULT NULL)');
        $this->addSql('INSERT INTO hatfield_session (id, cwd, prompt, created_at, updated_at) SELECT id, cwd, prompt, created_at, updated_at FROM __temp__hatfield_session');
        $this->addSql('UPDATE hatfield_session SET public_id = CAST(id AS TEXT) WHERE public_id IS NULL');
        $this->addSql('DROP TABLE __temp__hatfield_session');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9FFB4D3EB5B48B91 ON hatfield_session (public_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__hatfield_session AS SELECT id, cwd, prompt, created_at, updated_at FROM hatfield_session');
        $this->addSql('DROP TABLE hatfield_session');
        $this->addSql('CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cwd VARCHAR(255) NOT NULL, prompt VARCHAR(255) DEFAULT NULL, created_at VARCHAR(255) NOT NULL, updated_at VARCHAR(255) NOT NULL)');
        $this->addSql('INSERT INTO hatfield_session (id, cwd, prompt, created_at, updated_at) SELECT id, cwd, prompt, created_at, updated_at FROM __temp__hatfield_session');
        $this->addSql('DROP TABLE __temp__hatfield_session');
    }
}
