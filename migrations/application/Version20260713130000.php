<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deferred_tool_completion table for cross-process deferred tool correlation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deferred_tool_completion (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, deferred_id VARCHAR(36) NOT NULL, run_id VARCHAR(255) NOT NULL, turn_no INTEGER NOT NULL, step_id VARCHAR(255) NOT NULL, attempt INTEGER NOT NULL, idempotency_key VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, arguments CLOB NOT NULL, order_index INTEGER NOT NULL, tool_idempotency_key VARCHAR(255) DEFAULT NULL, mode VARCHAR(32) DEFAULT NULL, timeout_seconds INTEGER DEFAULT NULL, max_parallelism INTEGER DEFAULT NULL, assistant_message CLOB DEFAULT NULL, arg_schema CLOB DEFAULT NULL, tools_ref VARCHAR(255) DEFAULT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_tool_completion_deferred_id ON deferred_tool_completion (deferred_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_deferred_tool_completion_run_tool_call ON deferred_tool_completion (run_id, tool_call_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE deferred_tool_completion');
    }
}
