<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261005000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist indexed command identities and pending payloads independently of cache.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE run_command (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, run_id VARCHAR(255) NOT NULL, idempotency_key VARCHAR(255) NOT NULL, status CLOB NOT NULL, payload CLOB DEFAULT NULL, payload_hash VARCHAR(64) DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_run_command_pending ON run_command (run_id, status, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_run_command_identity ON run_command (run_id, idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE run_command');
    }
}
