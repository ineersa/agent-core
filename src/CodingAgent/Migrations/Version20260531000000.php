<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bridge migration: create project-owned tables for BackgroundProcessManager
 * and DbalToolBatchStore.
 *
 * Uses CREATE TABLE IF NOT EXISTS to handle the transition from the previous
 * raw-DBAL runtime CREATE TABLE approach. Existing databases already have
 * these tables; fresh databases get them via this migration.
 *
 * The doctrine_migration_versions table is managed by the Migrations library
 * itself (via MigrationRunner).
 */
final class Version20260531000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create background_process and tool_batch_state tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS background_process (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            pid            INTEGER NOT NULL,
            pgid           INTEGER,
            session_id     TEXT NOT NULL DEFAULT \'\',
            command        TEXT NOT NULL,
            log_path       TEXT NOT NULL,
            status_path    TEXT NOT NULL,
            started_at     TEXT NOT NULL,
            finished_at    TEXT,
            exit_code      INTEGER,
            stopped_by_user INTEGER NOT NULL DEFAULT 0,
            updated_at     TEXT NOT NULL
        )');

        $this->addSql('CREATE TABLE IF NOT EXISTS tool_batch_state (
            run_id       TEXT NOT NULL,
            turn_no      INTEGER NOT NULL,
            step_id      TEXT NOT NULL,
            batch_data   TEXT NOT NULL,
            created_at   TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at   TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (run_id, turn_no, step_id)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS tool_batch_state');
        $this->addSql('DROP TABLE IF EXISTS background_process');
    }
}
