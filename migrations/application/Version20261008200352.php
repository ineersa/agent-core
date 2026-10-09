<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008200352 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE run_command (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, run_id VARCHAR(255) NOT NULL, idempotency_key VARCHAR(255) NOT NULL, payload CLOB NOT NULL, payload_hash VARCHAR(64) NOT NULL)');
        $this->addSql('CREATE INDEX idx_run_command_pending ON run_command (run_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_run_command_identity ON run_command (run_id, idempotency_key)');
        $this->addSql('CREATE TABLE tool_batch_schedule (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, max_parallelism INTEGER NOT NULL, finalized BOOLEAN NOT NULL, expected_order_json CLOB NOT NULL, pending_queue_json CLOB NOT NULL, in_flight_json CLOB NOT NULL, awaiting_human_input_json CLOB NOT NULL, calls_json CLOB NOT NULL, results_json CLOB NOT NULL, applied_transition VARCHAR(64) NOT NULL, PRIMARY KEY (run_id, turn_no, step_id))');
        $this->addSql('CREATE INDEX idx_tool_batch_schedule_run ON tool_batch_schedule (run_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE run_command');
        $this->addSql('DROP TABLE tool_batch_schedule');
    }
}
