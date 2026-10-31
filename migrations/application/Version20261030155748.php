<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261030155748 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nullable background_process pid/started_at; drop obsolete Bash registration/ownership tables.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE bash_launch_ownership');
        $this->addSql('DROP TABLE bash_registration_receipt');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__background_process AS
            SELECT
              id,
              pid,
              pgid,
              session_id,
              command,
              log_path,
              status_path,
              started_at,
              finished_at,
              exit_code,
              stopped_by_user,
              status,
              created_at,
              updated_at,
              backgrounded_at,
              completion_notified_at,
              tool_call_id,
              background_requested,
              deadline_nanoseconds
            FROM
              background_process
        SQL);
        $this->addSql('DROP TABLE background_process');
        $this->addSql(<<<'SQL'
            CREATE TABLE background_process (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              pid INTEGER DEFAULT NULL,
              pgid INTEGER DEFAULT NULL,
              session_id VARCHAR(255) NOT NULL,
              command VARCHAR(255) NOT NULL,
              log_path VARCHAR(255) NOT NULL,
              status_path VARCHAR(255) NOT NULL,
              started_at DATETIME DEFAULT NULL,
              finished_at DATETIME DEFAULT NULL,
              exit_code INTEGER DEFAULT NULL,
              stopped_by_user BOOLEAN NOT NULL,
              status VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              backgrounded_at DATETIME DEFAULT NULL,
              completion_notified_at DATETIME DEFAULT NULL,
              tool_call_id VARCHAR(255) DEFAULT NULL,
              background_requested BOOLEAN DEFAULT 0 NOT NULL,
              deadline_nanoseconds BIGINT DEFAULT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO background_process (
              id, pid, pgid, session_id, command,
              log_path, status_path, started_at,
              finished_at, exit_code, stopped_by_user,
              status, created_at, updated_at, backgrounded_at,
              completion_notified_at, tool_call_id,
              background_requested, deadline_nanoseconds
            )
            SELECT
              id,
              pid,
              pgid,
              session_id,
              command,
              log_path,
              status_path,
              started_at,
              finished_at,
              exit_code,
              stopped_by_user,
              status,
              created_at,
              updated_at,
              backgrounded_at,
              completion_notified_at,
              tool_call_id,
              background_requested,
              deadline_nanoseconds
            FROM
              __temp__background_process
        SQL);
        $this->addSql('DROP TABLE __temp__background_process');
        $this->addSql('CREATE INDEX idx_bg_process_finished_at ON background_process (finished_at)');
        $this->addSql('CREATE INDEX idx_bg_process_session_id ON background_process (session_id)');
        $this->addSql('CREATE INDEX idx_bg_process_pid ON background_process (pid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE bash_launch_ownership (
              identity VARCHAR(64) NOT NULL COLLATE "BINARY",
              accepted BOOLEAN NOT NULL,
              owner_session_id INTEGER NOT NULL,
              PRIMARY KEY (identity),
              CONSTRAINT FK_D73FCEE33355759A FOREIGN KEY (owner_session_id) REFERENCES hatfield_session (id) ON
              UPDATE
                NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_D73FCEE33355759A ON bash_launch_ownership (owner_session_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE bash_registration_receipt (
              identity VARCHAR(64) NOT NULL COLLATE "BINARY",
              input_hash VARCHAR(64) NOT NULL COLLATE "BINARY",
              record_id INTEGER NOT NULL,
              pid INTEGER NOT NULL,
              log_path CLOB NOT NULL COLLATE "BINARY",
              owner_session_id INTEGER NOT NULL,
              PRIMARY KEY (identity),
              CONSTRAINT FK_4F82099F3355759A FOREIGN KEY (owner_session_id) REFERENCES hatfield_session (id) ON
              UPDATE
                NO ACTION ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_4F82099F3355759A ON bash_registration_receipt (owner_session_id)');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__background_process AS
            SELECT
              id,
              pid,
              pgid,
              session_id,
              command,
              log_path,
              status_path,
              started_at,
              finished_at,
              exit_code,
              stopped_by_user,
              backgrounded_at,
              tool_call_id,
              background_requested,
              deadline_nanoseconds,
              completion_notified_at,
              status,
              created_at,
              updated_at
            FROM
              background_process
        SQL);
        $this->addSql('DROP TABLE background_process');
        $this->addSql(<<<'SQL'
            CREATE TABLE background_process (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              pid INTEGER NOT NULL,
              pgid INTEGER DEFAULT NULL,
              session_id VARCHAR(255) NOT NULL,
              command VARCHAR(255) NOT NULL,
              log_path VARCHAR(255) NOT NULL,
              status_path VARCHAR(255) NOT NULL,
              started_at DATETIME NOT NULL,
              finished_at DATETIME DEFAULT NULL,
              exit_code INTEGER DEFAULT NULL,
              stopped_by_user BOOLEAN NOT NULL,
              backgrounded_at DATETIME DEFAULT NULL,
              tool_call_id VARCHAR(255) DEFAULT NULL,
              background_requested BOOLEAN DEFAULT 0 NOT NULL,
              deadline_nanoseconds BIGINT DEFAULT NULL,
              completion_notified_at DATETIME DEFAULT NULL,
              status VARCHAR(255) NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO background_process (
              id, pid, pgid, session_id, command,
              log_path, status_path, started_at,
              finished_at, exit_code, stopped_by_user,
              backgrounded_at, tool_call_id, background_requested,
              deadline_nanoseconds, completion_notified_at,
              status, created_at, updated_at
            )
            SELECT
              id,
              pid,
              pgid,
              session_id,
              command,
              log_path,
              status_path,
              started_at,
              finished_at,
              exit_code,
              stopped_by_user,
              backgrounded_at,
              tool_call_id,
              background_requested,
              deadline_nanoseconds,
              completion_notified_at,
              status,
              created_at,
              updated_at
            FROM
              __temp__background_process
        SQL);
        $this->addSql('DROP TABLE __temp__background_process');
        $this->addSql('CREATE INDEX idx_bg_process_pid ON background_process (pid)');
        $this->addSql('CREATE INDEX idx_bg_process_session_id ON background_process (session_id)');
        $this->addSql('CREATE INDEX idx_bg_process_finished_at ON background_process (finished_at)');
    }
}
