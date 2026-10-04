<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist narrow execution authorization and result references.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE execution_operation (effect_id VARCHAR(64) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, request_type VARCHAR(255) NOT NULL, result_type VARCHAR(255) NOT NULL, request_hash VARCHAR(64) NOT NULL, request_bytes INTEGER NOT NULL, owner_generation VARCHAR(64) NOT NULL, state VARCHAR(32) NOT NULL, claim_token VARCHAR(255) DEFAULT NULL, worker_instance VARCHAR(64) DEFAULT NULL, worker_pid INTEGER DEFAULT NULL, result_hash VARCHAR(64) DEFAULT NULL, result_bytes INTEGER DEFAULT NULL, disposition_transition VARCHAR(64) DEFAULT NULL, PRIMARY KEY(effect_id))');
        $this->addSql('CREATE INDEX idx_execution_operation_run_state ON execution_operation (run_id, state)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE execution_operation');
    }
}
