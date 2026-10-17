<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017195000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add canonical tool-completion recipient projection independent of pending mailbox rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tool_completion_recipient (destination_run_id VARCHAR(255) NOT NULL, message_id VARCHAR(255) NOT NULL, reference_json CLOB NOT NULL, received_turn_no INTEGER NOT NULL, received_sequence INTEGER NOT NULL, disposition VARCHAR(16) NOT NULL, state_reason VARCHAR(255) NOT NULL, applied_sequence INTEGER DEFAULT NULL, PRIMARY KEY(destination_run_id, message_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tool_completion_recipient');
    }
}
