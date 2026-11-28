<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261128023418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__tool_batch AS
            SELECT
              run_id,
              turn_no,
              step_id,
              finalized,
              expected_order_json,
              pending_queue_json,
              in_flight_json,
              awaiting_human_input_json,
              applied_transition
            FROM
              tool_batch
        SQL);
        $this->addSql('DROP TABLE tool_batch');
        $this->addSql(<<<'SQL'
            CREATE TABLE tool_batch (
              run_id VARCHAR(255) NOT NULL,
              turn_no INTEGER NOT NULL,
              step_id VARCHAR(255) NOT NULL,
              finalized BOOLEAN NOT NULL,
              expected_order_json CLOB NOT NULL,
              pending_queue_json CLOB NOT NULL,
              in_flight_json CLOB NOT NULL,
              awaiting_human_input_json CLOB NOT NULL,
              applied_transition VARCHAR(64) NOT NULL,
              PRIMARY KEY (run_id, turn_no, step_id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO tool_batch (
              run_id, turn_no, step_id, finalized,
              expected_order_json, pending_queue_json,
              in_flight_json, awaiting_human_input_json,
              applied_transition
            )
            SELECT
              run_id,
              turn_no,
              step_id,
              finalized,
              expected_order_json,
              pending_queue_json,
              in_flight_json,
              awaiting_human_input_json,
              applied_transition
            FROM
              __temp__tool_batch
        SQL);
        $this->addSql('DROP TABLE __temp__tool_batch');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__tool_deferred_completion AS
            SELECT
              id,
              deferred_id,
              run_id,
              turn_no,
              step_id,
              attempt,
              idempotency_key,
              tool_call_id,
              tool_name,
              body_reference_json,
              order_index,
              tool_idempotency_key,
              mode,
              timeout_seconds,
              tools_ref,
              status,
              created_at,
              updated_at,
              ready_at,
              backgrounded_at,
              interruption_reason,
              continuation_required,
              accepted_result_reference_json
            FROM
              tool_deferred_completion
        SQL);
        $this->addSql('DROP TABLE tool_deferred_completion');
        $this->addSql(<<<'SQL'
            CREATE TABLE tool_deferred_completion (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              deferred_id VARCHAR(36) NOT NULL,
              run_id VARCHAR(255) NOT NULL,
              turn_no INTEGER NOT NULL,
              step_id VARCHAR(255) NOT NULL,
              attempt INTEGER NOT NULL,
              idempotency_key VARCHAR(255) NOT NULL,
              tool_call_id VARCHAR(255) NOT NULL,
              tool_name VARCHAR(255) NOT NULL,
              body_reference_json VARCHAR(16384) NOT NULL,
              order_index INTEGER NOT NULL,
              tool_idempotency_key VARCHAR(255) DEFAULT NULL,
              mode VARCHAR(32) DEFAULT NULL,
              timeout_seconds INTEGER DEFAULT NULL,
              tools_ref VARCHAR(255) DEFAULT NULL,
              status VARCHAR(32) NOT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              ready_at DATETIME DEFAULT NULL,
              backgrounded_at DATETIME DEFAULT NULL,
              interruption_reason VARCHAR(32) DEFAULT NULL,
              continuation_required BOOLEAN DEFAULT 0 NOT NULL,
              accepted_result_reference_json VARCHAR(16384) DEFAULT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO tool_deferred_completion (
              id, deferred_id, run_id, turn_no, step_id,
              attempt, idempotency_key, tool_call_id,
              tool_name, body_reference_json,
              order_index, tool_idempotency_key,
              mode, timeout_seconds, tools_ref,
              status, created_at, updated_at, ready_at,
              backgrounded_at, interruption_reason,
              continuation_required, accepted_result_reference_json
            )
            SELECT
              id,
              deferred_id,
              run_id,
              turn_no,
              step_id,
              attempt,
              idempotency_key,
              tool_call_id,
              tool_name,
              body_reference_json,
              order_index,
              tool_idempotency_key,
              mode,
              timeout_seconds,
              tools_ref,
              status,
              created_at,
              updated_at,
              ready_at,
              backgrounded_at,
              interruption_reason,
              continuation_required,
              accepted_result_reference_json
            FROM
              __temp__tool_deferred_completion
        SQL);
        $this->addSql('DROP TABLE __temp__tool_deferred_completion');
        $this->addSql('CREATE INDEX idx_deferred_active_tool ON tool_deferred_completion (tool_name, status, run_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_tool_deferred_completion_run_tool_call ON tool_deferred_completion (run_id, tool_call_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_tool_deferred_completion_deferred_id ON tool_deferred_completion (deferred_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_deferred_pending ON tool_deferred_completion (run_id, status, turn_no, step_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tool_batch ADD COLUMN max_parallelism INTEGER NOT NULL');
        $this->addSql('ALTER TABLE tool_deferred_completion ADD COLUMN max_parallelism INTEGER DEFAULT NULL');
    }
}
