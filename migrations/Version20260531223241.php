<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260531223241 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE background_process (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, pid INTEGER NOT NULL, pgid INTEGER DEFAULT NULL, session_id VARCHAR(255) NOT NULL, command VARCHAR(255) NOT NULL, log_path VARCHAR(255) NOT NULL, status_path VARCHAR(255) NOT NULL, started_at VARCHAR(255) NOT NULL, finished_at VARCHAR(255) DEFAULT NULL, exit_code INTEGER DEFAULT NULL, stopped_by_user BOOLEAN NOT NULL, updated_at VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE TABLE tool_batch_state (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, batch_data CLOB NOT NULL, created_at VARCHAR(255) NOT NULL, updated_at VARCHAR(255) NOT NULL)');
        // SQLite platform does not emit UniqueConstraint from entity metadata.
        // The unique domain key (run_id, turn_no, step_id) is declared on the
        // ToolBatchState entity and enforced here.
        $this->addSql('CREATE UNIQUE INDEX tool_batch_run_step ON tool_batch_state(run_id, turn_no, step_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE background_process');
        $this->addSql('DROP TABLE tool_batch_state');
    }
}
