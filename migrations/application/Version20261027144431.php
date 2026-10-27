<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027144431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE background_completion_receipt ADD COLUMN origin CLOB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__background_completion_receipt AS SELECT identity, source_run_id, accepted_destination_run_id, destination_run_id, root_run_id, text, forwarded_text, disposition, deferred_id, invocation_reference_json FROM background_completion_receipt');
        $this->addSql('DROP TABLE background_completion_receipt');
        $this->addSql('CREATE TABLE background_completion_receipt (identity VARCHAR(255) NOT NULL, source_run_id VARCHAR(255) NOT NULL, accepted_destination_run_id VARCHAR(255) NOT NULL, destination_run_id VARCHAR(255) NOT NULL, root_run_id VARCHAR(255) NOT NULL, text CLOB NOT NULL, forwarded_text CLOB NOT NULL, disposition VARCHAR(32) NOT NULL, deferred_id VARCHAR(36) DEFAULT NULL, invocation_reference_json CLOB DEFAULT NULL, PRIMARY KEY (identity))');
        $this->addSql('INSERT INTO background_completion_receipt (identity, source_run_id, accepted_destination_run_id, destination_run_id, root_run_id, text, forwarded_text, disposition, deferred_id, invocation_reference_json) SELECT identity, source_run_id, accepted_destination_run_id, destination_run_id, root_run_id, text, forwarded_text, disposition, deferred_id, invocation_reference_json FROM __temp__background_completion_receipt');
        $this->addSql('DROP TABLE __temp__background_completion_receipt');
        $this->addSql('CREATE INDEX background_completion_forwarding ON background_completion_receipt (root_run_id, disposition)');
    }
}
