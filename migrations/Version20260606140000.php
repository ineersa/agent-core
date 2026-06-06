<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tool_question table for cross-process tool-local questions (TOOLS-09B)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tool_question (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            request_id VARCHAR(255) NOT NULL,
            run_id VARCHAR(255) NOT NULL,
            tool_call_id VARCHAR(255) NOT NULL,
            tool_name VARCHAR(255) NOT NULL,
            pid INTEGER NOT NULL,
            log_path VARCHAR(255) NOT NULL,
            command_preview VARCHAR(200) NOT NULL,
            prompt VARCHAR(255) NOT NULL,
            kind VARCHAR(50) NOT NULL DEFAULT \'confirm\',
            status VARCHAR(255) NOT NULL DEFAULT \'pending\',
            answer BOOLEAN DEFAULT NULL,
            emitted_at DATETIME DEFAULT NULL,
            answered_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TOOL_QUESTION_REQUEST_ID ON tool_question (request_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tool_question');
    }
}
