<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023002325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retain code-mode hosts, exact deferred invocation references, and shared caller acknowledgement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE code_mode_execution (identity VARCHAR(255) NOT NULL, run_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, call_reference CLOB NOT NULL, ready BOOLEAN NOT NULL, settled BOOLEAN NOT NULL, raw_reference CLOB DEFAULT NULL, result_reference CLOB DEFAULT NULL, PRIMARY KEY (identity))');
        $this->addSql('CREATE UNIQUE INDEX code_mode_invocation ON code_mode_execution (run_id, tool_call_id)');
        $this->addSql('ALTER TABLE tool_deferred_completion ADD COLUMN invocation_reference_json VARCHAR(16384) DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_deferred_completion ADD COLUMN acknowledged_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE code_mode_execution');
        $this->addSql('CREATE TEMPORARY TABLE __temp__tool_deferred_completion AS SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, created_at, updated_at FROM tool_deferred_completion');
        $this->addSql('DROP TABLE tool_deferred_completion');
        $this->addSql('CREATE TABLE tool_deferred_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, body_reference_json VARCHAR(16384) NOT NULL, order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL, mode VARCHAR(32) DEFAULT NULL, timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, tools_ref VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO tool_deferred_completion (id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, created_at, updated_at) SELECT id, deferred_id, run_id, turn_no, step_id, attempt, idempotency_key, tool_call_id, tool_name, body_reference_json, order_index, tool_idempotency_key, mode, timeout_seconds, max_parallelism, tools_ref, status, created_at, updated_at FROM __temp__tool_deferred_completion');
        $this->addSql('DROP TABLE __temp__tool_deferred_completion');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_deferred_id ON tool_deferred_completion (deferred_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_deferred_completion_run_tool_call ON tool_deferred_completion (run_id, tool_call_id)');
    }
}
