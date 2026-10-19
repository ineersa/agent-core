<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019210134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__tool_question AS SELECT id, request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview, prompt, kind, status, answer, emitted_at, answered_at, created_at, updated_at, schema FROM tool_question');
        $this->addSql('DROP TABLE tool_question');
        $this->addSql('CREATE TABLE tool_question (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, request_id VARCHAR(255) NOT NULL, run_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, pid INTEGER NOT NULL, log_path VARCHAR(255) NOT NULL, command_preview VARCHAR(200) NOT NULL, prompt VARCHAR(255) NOT NULL, kind VARCHAR(50) DEFAULT \'confirm\' NOT NULL, status VARCHAR(255) DEFAULT \'pending\' NOT NULL, answer BOOLEAN DEFAULT NULL, emitted_at DATETIME DEFAULT NULL, answered_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, schema CLOB DEFAULT NULL)');
        $this->addSql('INSERT INTO tool_question (id, request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview, prompt, kind, status, answer, emitted_at, answered_at, created_at, updated_at, schema) SELECT id, request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview, prompt, kind, status, answer, emitted_at, answered_at, created_at, updated_at, schema FROM __temp__tool_question');
        $this->addSql('DROP TABLE __temp__tool_question');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_TOOL_QUESTION_REQUEST_ID ON tool_question (request_id)');
        $this->addSql('CREATE INDEX idx_tool_question_unemitted ON tool_question (status, emitted_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__tool_question AS SELECT id, request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview, prompt, kind, status, answer, schema, emitted_at, answered_at, created_at, updated_at FROM tool_question');
        $this->addSql('DROP TABLE tool_question');
        $this->addSql('CREATE TABLE tool_question (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, request_id VARCHAR(255) NOT NULL, run_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, tool_name VARCHAR(255) NOT NULL, pid INTEGER NOT NULL, log_path VARCHAR(255) NOT NULL, command_preview VARCHAR(200) NOT NULL, prompt VARCHAR(255) NOT NULL, kind VARCHAR(50) DEFAULT \'confirm\' NOT NULL, status VARCHAR(255) DEFAULT \'pending\' NOT NULL, answer BOOLEAN DEFAULT NULL, schema CLOB DEFAULT NULL, emitted_at DATETIME DEFAULT NULL, answered_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO tool_question (id, request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview, prompt, kind, status, answer, schema, emitted_at, answered_at, created_at, updated_at) SELECT id, request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview, prompt, kind, status, answer, schema, emitted_at, answered_at, created_at, updated_at FROM __temp__tool_question');
        $this->addSql('DROP TABLE __temp__tool_question');
        $this->addSql('CREATE UNIQUE INDEX uniq_tool_question_request_id ON tool_question (request_id)');
    }
}
