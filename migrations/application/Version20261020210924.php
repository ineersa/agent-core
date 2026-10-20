<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020210924 extends AbstractMigration
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
        $this->addSql('CREATE TABLE tool_batch_call (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, order_index INTEGER NOT NULL, mode VARCHAR(32) NOT NULL, body_reference_json VARCHAR(16384) NOT NULL, PRIMARY KEY (run_id, turn_no, step_id, tool_call_id))');
        $this->addSql('CREATE TABLE tool_batch_result (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, order_index INTEGER NOT NULL, body_reference_json VARCHAR(16384) NOT NULL, PRIMARY KEY (run_id, turn_no, step_id, tool_call_id))');
        $this->addSql('CREATE TABLE tool_deferred_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, body_reference_json VARCHAR(16384) NOT NULL, order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL, mode VARCHAR(32) DEFAULT NULL, timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, tools_ref VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_deferred_id ON tool_deferred_completion (deferred_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_run_tool_call ON tool_deferred_completion (run_id, tool_call_id)');
        $this->addSql('DROP TABLE deferred_tool_completion');
        $this->addSql('DROP TABLE tool_batch_schedule');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deferred_tool_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL COLLATE "BINARY", run_id VARCHAR(255) NOT NULL COLLATE "BINARY", turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL COLLATE "BINARY", attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL COLLATE "BINARY", tool_call_id VARCHAR(255) NOT NULL COLLATE "BINARY", tool_name VARCHAR(255) NOT NULL COLLATE "BINARY", arguments CLOB NOT NULL COLLATE "BINARY", order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL COLLATE "BINARY", mode VARCHAR(32) DEFAULT NULL COLLATE "BINARY", timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, assistant_message CLOB DEFAULT NULL COLLATE "BINARY", arg_schema CLOB DEFAULT NULL COLLATE "BINARY", tools_ref VARCHAR(255) DEFAULT NULL COLLATE "BINARY", status VARCHAR(32) NOT NULL COLLATE "BINARY", created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_tool_completion_run_tool_call ON deferred_tool_completion (run_id, tool_call_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_tool_completion_deferred_id ON deferred_tool_completion (deferred_id)');
        $this->addSql('CREATE TABLE tool_batch_schedule (run_id VARCHAR(255) NOT NULL COLLATE "BINARY", turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL COLLATE "BINARY", max_parallelism INTEGER NOT NULL, finalized BOOLEAN NOT NULL, expected_order_json CLOB NOT NULL COLLATE "BINARY", pending_queue_json CLOB NOT NULL COLLATE "BINARY", in_flight_json CLOB NOT NULL COLLATE "BINARY", awaiting_human_input_json CLOB NOT NULL COLLATE "BINARY", calls_json CLOB NOT NULL COLLATE "BINARY", results_json CLOB NOT NULL COLLATE "BINARY", applied_transition VARCHAR(64) NOT NULL COLLATE "BINARY", PRIMARY KEY (run_id, turn_no, step_id))');
        $this->addSql('CREATE INDEX idx_tool_batch_schedule_run ON tool_batch_schedule (run_id)');
        $this->addSql('DROP TABLE tool_batch');
        $this->addSql('DROP TABLE tool_batch_call');
        $this->addSql('DROP TABLE tool_batch_result');
        $this->addSql('DROP TABLE tool_deferred_completion');
    }
}
