<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120181155 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE code_mode_call (invocationId VARCHAR(36) NOT NULL, callId VARCHAR(16) NOT NULL, fingerprint VARCHAR(64) NOT NULL, childId VARCHAR(36) DEFAULT NULL, replyReference CLOB DEFAULT NULL, PRIMARY KEY (invocationId, callId))');
        $this->addSql('CREATE TABLE code_mode_reported_return (invocationId VARCHAR(36) NOT NULL, replyReference CLOB NOT NULL, PRIMARY KEY (invocationId))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE code_mode_call');
        $this->addSql('DROP TABLE code_mode_reported_return');
    }
}
