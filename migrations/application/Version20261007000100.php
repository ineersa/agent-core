<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track logical tool-call and deferred ownership on the common invocation ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE execution_operation ADD logical_tool_call_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE execution_operation ADD deferred_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_execution_operation_deferred ON execution_operation (deferred_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_execution_operation_deferred');
        $this->addSql('ALTER TABLE execution_operation DROP COLUMN deferred_id');
        $this->addSql('ALTER TABLE execution_operation DROP COLUMN logical_tool_call_id');
    }
}
