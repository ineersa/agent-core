<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Creates the disposable, payload-free operational projection tables. */
final class Version20260827120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bounded payload-free run operational projection tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE run_operational_state (
    run_id VARCHAR(255) NOT NULL PRIMARY KEY,
    owner_session_id VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    turn_no INTEGER NOT NULL,
    active_step_id VARCHAR(255) DEFAULT NULL,
    operation_turn_no INTEGER DEFAULT NULL,
    operation_step_id VARCHAR(255) DEFAULT NULL,
    operation_attempt INTEGER DEFAULT NULL,
    operation_key VARCHAR(255) DEFAULT NULL,
    last_applied_advance_key VARCHAR(255) DEFAULT NULL,
    last_applied_compaction_key VARCHAR(255) DEFAULT NULL,
    retryable_failure BOOLEAN NOT NULL,
    retry_attempts INTEGER NOT NULL,
    last_event_sequence INTEGER NOT NULL,
    transition_version INTEGER NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CHECK (length(run_id) BETWEEN 1 AND 255),
    CHECK (length(owner_session_id) BETWEEN 1 AND 255),
    CHECK (length(status) BETWEEN 1 AND 32),
    CHECK (active_step_id IS NULL OR length(active_step_id) BETWEEN 1 AND 255),
    CHECK (operation_step_id IS NULL OR length(operation_step_id) BETWEEN 1 AND 255),
    CHECK (operation_key IS NULL OR length(operation_key) BETWEEN 1 AND 255),
    CHECK (last_applied_advance_key IS NULL OR length(last_applied_advance_key) BETWEEN 1 AND 255),
    CHECK (last_applied_compaction_key IS NULL OR length(last_applied_compaction_key) BETWEEN 1 AND 255),
    CHECK (turn_no >= 0),
    CHECK (retry_attempts >= 0),
    CHECK (last_event_sequence >= 0),
    CHECK (transition_version >= 0),
    CHECK (operation_turn_no IS NULL OR operation_turn_no >= 0),
    CHECK (operation_attempt IS NULL OR operation_attempt >= 1),
    CHECK ((operation_turn_no IS NULL AND operation_step_id IS NULL AND operation_attempt IS NULL AND operation_key IS NULL)
        OR (operation_turn_no IS NOT NULL AND operation_step_id IS NOT NULL AND operation_attempt IS NOT NULL AND operation_key IS NOT NULL))
)
SQL);
        $this->addSql('CREATE INDEX idx_run_operational_state_owner ON run_operational_state (owner_session_id)');
        $this->addSql('CREATE INDEX idx_run_operational_state_status ON run_operational_state (status)');
        $this->addSql(<<<'SQL'
CREATE TABLE run_operational_tool_call (
    run_id VARCHAR(255) NOT NULL,
    batch_id VARCHAR(255) NOT NULL,
    tool_call_id VARCHAR(255) NOT NULL,
    order_index INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempt INTEGER NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (run_id, batch_id, tool_call_id),
    FOREIGN KEY (run_id) REFERENCES run_operational_state (run_id) ON DELETE CASCADE,
    CHECK (length(run_id) BETWEEN 1 AND 255),
    CHECK (length(batch_id) BETWEEN 1 AND 255),
    CHECK (length(tool_call_id) BETWEEN 1 AND 255),
    CHECK (length(status) BETWEEN 1 AND 32),
    CHECK (order_index >= 0),
    CHECK (attempt >= 0)
)
SQL);
        $this->addSql('CREATE INDEX idx_run_operational_tool_current ON run_operational_tool_call (run_id, batch_id, status, order_index)');
        $this->addSql(<<<'SQL'
CREATE TABLE run_operational_human_input (
    run_id VARCHAR(255) NOT NULL,
    question_id VARCHAR(255) NOT NULL,
    order_index INTEGER NOT NULL,
    continuation_kind VARCHAR(32) NOT NULL,
    tool_call_id VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (run_id, question_id),
    FOREIGN KEY (run_id) REFERENCES run_operational_state (run_id) ON DELETE CASCADE,
    CHECK (length(run_id) BETWEEN 1 AND 255),
    CHECK (length(question_id) BETWEEN 1 AND 255),
    CHECK (length(continuation_kind) BETWEEN 1 AND 32),
    CHECK (tool_call_id IS NULL OR length(tool_call_id) BETWEEN 1 AND 255),
    CHECK (length(status) BETWEEN 1 AND 32),
    CHECK (order_index >= 0)
)
SQL);
        $this->addSql('CREATE INDEX idx_run_operational_human_current ON run_operational_human_input (run_id, status, order_index)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS run_operational_human_input');
        $this->addSql('DROP TABLE IF EXISTS run_operational_tool_call');
        $this->addSql('DROP TABLE IF EXISTS run_operational_state');
    }
}
