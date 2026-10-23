<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023000232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__hatfield_session AS SELECT id, cwd, prompt, parent_id, root_id, model, model_provider, model_name, reasoning, created_at, updated_at, name, provider_cache_key FROM hatfield_session');
        $this->addSql('DROP TABLE hatfield_session');
        $this->addSql('CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cwd VARCHAR(255) NOT NULL, prompt VARCHAR(255) DEFAULT NULL, parent_id VARCHAR(255) DEFAULT NULL, root_id VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, model_provider VARCHAR(255) DEFAULT NULL, model_name VARCHAR(255) DEFAULT NULL, reasoning VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(200) DEFAULT \'\' NOT NULL, provider_cache_key VARCHAR(36) DEFAULT NULL)');
        $this->addSql('INSERT INTO hatfield_session (id, cwd, prompt, parent_id, root_id, model, model_provider, model_name, reasoning, created_at, updated_at, name, provider_cache_key) SELECT id, cwd, prompt, parent_id, root_id, model, model_provider, model_name, reasoning, created_at, updated_at, name, provider_cache_key FROM __temp__hatfield_session');
        $this->addSql('DROP TABLE __temp__hatfield_session');
        $this->addSql('CREATE UNIQUE INDEX uniq_hatfield_session_provider_cache_key ON hatfield_session (provider_cache_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hatfield_session ADD COLUMN reasoning_baseline CLOB DEFAULT NULL');
    }
}
