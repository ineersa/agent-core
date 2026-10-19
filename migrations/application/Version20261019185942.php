<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019185942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE tool_question');
        $this->addSql('ALTER TABLE background_process ADD COLUMN tool_call_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE background_process ADD COLUMN background_requested BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE background_process ADD COLUMN deadline_nanoseconds BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tool_question (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, request_id VARCHAR(255) NOT NULL COLLATE "BINARY", run_id VARCHAR(255) NOT NULL COLLATE "BINARY", tool_call_id VARCHAR(255) NOT NULL COLLATE "BINARY", tool_name VARCHAR(255) NOT NULL COLLATE "BINARY", pid INTEGER NOT NULL, log_path VARCHAR(255) NOT NULL COLLATE "BINARY", command_preview VARCHAR(200) NOT NULL COLLATE "BINARY", prompt VARCHAR(255) NOT NULL COLLATE "BINARY", kind VARCHAR(50) DEFAULT \'confirm\' NOT NULL COLLATE "BINARY", status VARCHAR(255) DEFAULT \'pending\' NOT NULL COLLATE "BINARY", answer BOOLEAN DEFAULT NULL, emitted_at DATETIME DEFAULT NULL, answered_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, schema CLOB DEFAULT NULL COLLATE "BINARY")');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TOOL_QUESTION_REQUEST_ID ON tool_question (request_id)');
        $this->addSql('CREATE TEMPORARY TABLE __temp__background_process AS SELECT id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, backgrounded_at, completion_notified_at, status, created_at, updated_at FROM background_process');
        $this->addSql('DROP TABLE background_process');
        $this->addSql('CREATE TABLE background_process (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, pid INTEGER NOT NULL, pgid INTEGER DEFAULT NULL, session_id VARCHAR(255) NOT NULL, command VARCHAR(255) NOT NULL, log_path VARCHAR(255) NOT NULL, status_path VARCHAR(255) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, exit_code INTEGER DEFAULT NULL, stopped_by_user BOOLEAN NOT NULL, backgrounded_at DATETIME DEFAULT NULL, completion_notified_at DATETIME DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO background_process (id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, backgrounded_at, completion_notified_at, status, created_at, updated_at) SELECT id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, backgrounded_at, completion_notified_at, status, created_at, updated_at FROM __temp__background_process');
        $this->addSql('DROP TABLE __temp__background_process');
        $this->addSql('CREATE INDEX idx_bg_process_pid ON background_process (pid)');
        $this->addSql('CREATE INDEX idx_bg_process_session_id ON background_process (session_id)');
        $this->addSql('CREATE INDEX idx_bg_process_finished_at ON background_process (finished_at)');
    }
}
