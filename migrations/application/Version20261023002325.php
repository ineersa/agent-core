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
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE code_mode_execution (identity VARCHAR(255) NOT NULL, run_id VARCHAR(255) NOT NULL, tool_call_id VARCHAR(255) NOT NULL, call_reference CLOB NOT NULL, ready BOOLEAN NOT NULL, detached BOOLEAN NOT NULL, settled BOOLEAN NOT NULL, raw_reference CLOB DEFAULT NULL, result_reference CLOB DEFAULT NULL, PRIMARY KEY (identity))');
        $this->addSql('CREATE UNIQUE INDEX code_mode_invocation ON code_mode_execution (run_id, tool_call_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE code_mode_execution');
    }
}
