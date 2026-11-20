<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120193110 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE code_mode_call (invocationId VARCHAR(36) NOT NULL, callId VARCHAR(16) NOT NULL, fingerprint VARCHAR(64) NOT NULL, replyReference CLOB DEFAULT NULL, PRIMARY KEY (invocationId, callId))');
        $this->addSql('CREATE TABLE code_mode_reported_return (invocationId VARCHAR(36) NOT NULL, replyReference CLOB NOT NULL, PRIMARY KEY (invocationId))');
        $this->addSql('CREATE TEMPORARY TABLE __temp__background_process AS SELECT id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, status, created_at, updated_at, backgrounded_at, completion_notified_at, tool_call_id, background_requested, deadline_nanoseconds FROM background_process');
        $this->addSql('DROP TABLE background_process');
        $this->addSql('CREATE TABLE background_process (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, pid INTEGER DEFAULT NULL, pgid INTEGER DEFAULT NULL, session_id VARCHAR(255) NOT NULL, command VARCHAR(255) NOT NULL, log_path VARCHAR(255) NOT NULL, status_path VARCHAR(255) NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, exit_code INTEGER DEFAULT NULL, stopped_by_user BOOLEAN NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, backgrounded_at DATETIME DEFAULT NULL, completion_notified_at DATETIME DEFAULT NULL, tool_call_id VARCHAR(255) DEFAULT NULL, background_requested BOOLEAN DEFAULT 0 NOT NULL, deadline_nanoseconds BIGINT DEFAULT NULL, code_mode_id VARCHAR(36) DEFAULT NULL, code_mode_launch CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO background_process (id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, status, created_at, updated_at, backgrounded_at, completion_notified_at, tool_call_id, background_requested, deadline_nanoseconds) SELECT id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, status, created_at, updated_at, backgrounded_at, completion_notified_at, tool_call_id, background_requested, deadline_nanoseconds FROM __temp__background_process');
        $this->addSql('DROP TABLE __temp__background_process');
        $this->addSql('CREATE INDEX idx_bg_process_pid ON background_process (pid)');
        $this->addSql('CREATE INDEX idx_bg_process_session_id ON background_process (session_id)');
        $this->addSql('CREATE INDEX idx_bg_process_finished_at ON background_process (finished_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42D909BC4F42C1D6 ON background_process (code_mode_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE code_mode_call');
        $this->addSql('DROP TABLE code_mode_reported_return');
        $this->addSql('CREATE TEMPORARY TABLE __temp__background_process AS SELECT id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, backgrounded_at, tool_call_id, background_requested, deadline_nanoseconds, completion_notified_at, status, created_at, updated_at FROM background_process');
        $this->addSql('DROP TABLE background_process');
        $this->addSql('CREATE TABLE background_process (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, pid INTEGER DEFAULT NULL, pgid INTEGER DEFAULT NULL, session_id VARCHAR(255) NOT NULL, command VARCHAR(255) NOT NULL, log_path VARCHAR(255) NOT NULL, status_path VARCHAR(255) NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, exit_code INTEGER DEFAULT NULL, stopped_by_user BOOLEAN NOT NULL, backgrounded_at DATETIME DEFAULT NULL, tool_call_id VARCHAR(255) DEFAULT NULL, background_requested BOOLEAN DEFAULT 0 NOT NULL, deadline_nanoseconds BIGINT DEFAULT NULL, completion_notified_at DATETIME DEFAULT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO background_process (id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, backgrounded_at, tool_call_id, background_requested, deadline_nanoseconds, completion_notified_at, status, created_at, updated_at) SELECT id, pid, pgid, session_id, command, log_path, status_path, started_at, finished_at, exit_code, stopped_by_user, backgrounded_at, tool_call_id, background_requested, deadline_nanoseconds, completion_notified_at, status, created_at, updated_at FROM __temp__background_process');
        $this->addSql('DROP TABLE __temp__background_process');
        $this->addSql('CREATE INDEX idx_bg_process_pid ON background_process (pid)');
        $this->addSql('CREATE INDEX idx_bg_process_session_id ON background_process (session_id)');
        $this->addSql('CREATE INDEX idx_bg_process_finished_at ON background_process (finished_at)');
    }
}
