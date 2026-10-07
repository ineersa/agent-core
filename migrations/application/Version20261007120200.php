<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce one execution_operation row per frozen invocation identity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_execution_operation_invocation ON execution_operation (run_id, turn_no, step_id, attempt, idempotency_key, request_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_execution_operation_invocation');
    }
}