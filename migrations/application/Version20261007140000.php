<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist small durable control-message outbox obligations after verified append.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE control_message_outbox (run_id VARCHAR(255) NOT NULL, identity VARCHAR(128) NOT NULL, destination VARCHAR(32) NOT NULL, payload_json CLOB NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (run_id, identity))');
        $this->addSql('CREATE INDEX idx_control_message_outbox_run ON control_message_outbox (run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE control_message_outbox');
    }
}
