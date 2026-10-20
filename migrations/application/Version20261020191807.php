<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020191807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tool_batch (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, max_parallelism INTEGER NOT NULL, finalized BOOLEAN NOT NULL, expected_order_json CLOB NOT NULL, pending_queue_json CLOB NOT NULL, in_flight_json CLOB NOT NULL, awaiting_human_input_json CLOB NOT NULL, applied_transition VARCHAR(64) NOT NULL, PRIMARY KEY (run_id, turn_no, step_id))');
        $this->addSql('CREATE INDEX idx_tool_batch_run ON tool_batch (run_id)');
        $this->addSql('CREATE TABLE tool_batch_call (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, order_index INTEGER NOT NULL, mode VARCHAR(32) NOT NULL, payload_json CLOB NOT NULL, PRIMARY KEY (run_id, turn_no, step_id, tool_call_id))');
        $this->addSql('CREATE TABLE tool_batch_result (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, order_index INTEGER NOT NULL, payload_json CLOB NOT NULL, PRIMARY KEY (run_id, turn_no, step_id, tool_call_id))');
        $this->addSql('DROP TABLE tool_batch_schedule');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tool_batch_schedule (run_id VARCHAR(255) NOT NULL COLLATE "BINARY", turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL COLLATE "BINARY", max_parallelism INTEGER NOT NULL, finalized BOOLEAN NOT NULL, expected_order_json CLOB NOT NULL COLLATE "BINARY", pending_queue_json CLOB NOT NULL COLLATE "BINARY", in_flight_json CLOB NOT NULL COLLATE "BINARY", awaiting_human_input_json CLOB NOT NULL COLLATE "BINARY", calls_json CLOB NOT NULL COLLATE "BINARY", results_json CLOB NOT NULL COLLATE "BINARY", applied_transition VARCHAR(64) NOT NULL COLLATE "BINARY", PRIMARY KEY (run_id, turn_no, step_id))');
        $this->addSql('CREATE INDEX idx_tool_batch_schedule_run ON tool_batch_schedule (run_id)');
        $this->addSql('DROP TABLE tool_batch');
        $this->addSql('DROP TABLE tool_batch_call');
        $this->addSql('DROP TABLE tool_batch_result');
    }
}
