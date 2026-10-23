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
        return 'Drop hatfield_session.reasoning_baseline while preserving AUTOINCREMENT high-water.';
    }

    public function up(Schema $schema): void
    {
        // Generated SQLite table rebuild drops sqlite_sequence with the old table.
        // Session ids are durable directory names and session_id === run_id; OM and
        // session artifacts are keyed by that numeric id. Reusing a retired id after
        // upgrade would attach a new run to leftover OM/session state. Capture the
        // high-water mark in SQL (runtime executor runs planned SQL only) and restore
        // it after the rebuild so deleted ids stay retired.
        $this->addSql('CREATE TEMPORARY TABLE __temp__hatfield_session AS SELECT id, cwd, prompt, parent_id, root_id, model, model_provider, model_name, reasoning, created_at, updated_at, name, provider_cache_key FROM hatfield_session');
        $this->addSql('CREATE TEMPORARY TABLE __temp__hatfield_session_seq AS SELECT seq FROM sqlite_sequence WHERE name = \'hatfield_session\'');
        $this->addSql('DROP TABLE hatfield_session');
        $this->addSql('CREATE TABLE hatfield_session (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, cwd VARCHAR(255) NOT NULL, prompt VARCHAR(255) DEFAULT NULL, parent_id VARCHAR(255) DEFAULT NULL, root_id VARCHAR(255) DEFAULT NULL, model VARCHAR(255) DEFAULT NULL, model_provider VARCHAR(255) DEFAULT NULL, model_name VARCHAR(255) DEFAULT NULL, reasoning VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, name VARCHAR(200) DEFAULT \'\' NOT NULL, provider_cache_key VARCHAR(36) DEFAULT NULL)');
        $this->addSql('INSERT INTO hatfield_session (id, cwd, prompt, parent_id, root_id, model, model_provider, model_name, reasoning, created_at, updated_at, name, provider_cache_key) SELECT id, cwd, prompt, parent_id, root_id, model, model_provider, model_name, reasoning, created_at, updated_at, name, provider_cache_key FROM __temp__hatfield_session');
        $this->addSql('DROP TABLE __temp__hatfield_session');
        $this->addSql('UPDATE sqlite_sequence SET seq = (SELECT MAX(v) FROM (SELECT seq AS v FROM sqlite_sequence WHERE name = \'hatfield_session\' UNION ALL SELECT seq AS v FROM __temp__hatfield_session_seq)) WHERE name = \'hatfield_session\'');
        $this->addSql('INSERT INTO sqlite_sequence (name, seq) SELECT \'hatfield_session\', seq FROM __temp__hatfield_session_seq WHERE NOT EXISTS (SELECT 1 FROM sqlite_sequence WHERE name = \'hatfield_session\')');
        $this->addSql('DROP TABLE __temp__hatfield_session_seq');
        $this->addSql('CREATE UNIQUE INDEX uniq_hatfield_session_provider_cache_key ON hatfield_session (provider_cache_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hatfield_session ADD COLUMN reasoning_baseline CLOB DEFAULT NULL');
    }
}
