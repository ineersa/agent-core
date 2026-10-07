<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace intermediate tool-batch body payloads with ledger reference JSON columns.';
    }

    public function up(Schema $schema): void
    {
        // Unreleased draft DBs may still carry serialized body blobs under the
        // earlier Version20261007120000 shape. Rebuild the scheduling table as
        // membership/queue state plus current invocation/result references.
        $this->addSql('DROP TABLE IF EXISTS tool_batch_schedule');
        $this->addSql('CREATE TABLE tool_batch_schedule (run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, max_parallelism INTEGER NOT NULL, finalized BOOLEAN NOT NULL, expected_order_json CLOB NOT NULL, pending_queue_json CLOB NOT NULL, in_flight_json CLOB NOT NULL, awaiting_human_input_json CLOB NOT NULL, calls_json CLOB NOT NULL, results_json CLOB NOT NULL, applied_transition VARCHAR(64) NOT NULL, PRIMARY KEY (run_id, turn_no, step_id))');
        $this->addSql('CREATE INDEX idx_tool_batch_schedule_run ON tool_batch_schedule (run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tool_batch_schedule');
    }
}
