<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create messenger_messages table before messenger:consume workers launch
 * to eliminate the auto_setup race when multiple consumers start concurrently.
 *
 * The Symfony Messenger Doctrine transport (Connection::setup()) auto-creates
 * this table on first send/get when it does not exist.  When the controller
 * launches run_control, llm, and tool consumers together, all three race to
 * CREATE TABLE — only the first succeeds, the rest fail with "table already
 * exists".
 *
 * Legacy: table was created on the default DB via StartupDatabaseMigrator.
 * Runtime now uses MessengerTransportSchemaEnsurer on connection
 * messenger_transport (.hatfield/messenger-transport.sqlite). This migration
 * remains for dev doctrine:migrations history on existing checkouts.
 *
 * @see \Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection::configureSchemaTable()
 * @see src/CodingAgent/Migrations/StartupDatabaseMigrator.php
 */
final class Version20260617141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages table ahead-of-time to prevent auto_setup race on concurrent consumer startup';
    }

    public function up(Schema $schema): void
    {
        // CREATE TABLE IF NOT EXISTS handles both fresh DBs (migration creates
        // the table first) and existing DBs where auto_setup already created
        // it in a previous runtime session.
        $this->addSql('CREATE TABLE IF NOT EXISTS messenger_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            body CLOB NOT NULL,
            headers CLOB NOT NULL,
            queue_name VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL,
            available_at DATETIME NOT NULL,
            delivered_at DATETIME DEFAULT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
