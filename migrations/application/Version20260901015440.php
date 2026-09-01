<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260901015440 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__run_operational_state AS SELECT run_id, parent_run_id, owner_session_id, status, turn_no, active_step_id, last_applied_advance_key, last_applied_compaction_key, retryable_failure, retry_attempts, last_event_sequence, transition_version, created_at, updated_at FROM run_operational_state');
        $this->addSql('DROP TABLE run_operational_state');
        $this->addSql('CREATE TABLE run_operational_state (run_id VARCHAR(255) NOT NULL, parent_run_id VARCHAR(255) DEFAULT NULL, owner_session_id VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, turn_no INTEGER NOT NULL, active_step_id VARCHAR(255) DEFAULT NULL, last_applied_advance_key VARCHAR(255) DEFAULT NULL, last_applied_compaction_key VARCHAR(255) DEFAULT NULL, retryable_failure BOOLEAN NOT NULL, retry_attempts INTEGER NOT NULL, last_event_sequence INTEGER NOT NULL, transition_version INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (run_id))');
        $this->addSql('INSERT INTO run_operational_state (run_id, parent_run_id, owner_session_id, status, turn_no, active_step_id, last_applied_advance_key, last_applied_compaction_key, retryable_failure, retry_attempts, last_event_sequence, transition_version, created_at, updated_at) SELECT run_id, parent_run_id, owner_session_id, status, turn_no, active_step_id, last_applied_advance_key, last_applied_compaction_key, retryable_failure, retry_attempts, last_event_sequence, transition_version, created_at, updated_at FROM __temp__run_operational_state');
        $this->addSql('DROP TABLE __temp__run_operational_state');
        $this->addSql('CREATE INDEX idx_run_operational_state_status ON run_operational_state (status)');
        $this->addSql('CREATE INDEX idx_run_operational_state_owner ON run_operational_state (owner_session_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE run_operational_state ADD COLUMN operation_turn_no INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE run_operational_state ADD COLUMN operation_step_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE run_operational_state ADD COLUMN operation_attempt INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE run_operational_state ADD COLUMN operation_key VARCHAR(255) DEFAULT NULL');
    }
}
