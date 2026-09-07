<?php

declare(strict_types=1);

namespace DoctrineMigrations\MessengerTransport;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create messenger_messages ahead of consumer launch.
 *
 * Startup must migrate the dedicated transport DB before ConsumerSupervisor
 * starts messenger:consume children. CREATE TABLE/INDEX IF NOT EXISTS keeps
 * this safe when the table already exists with matching schema and no
 * doctrine_migration_versions row (for example after auto_setup or an older
 * schema path), without deleting rows or requiring a manual metadata mark.
 */
final class Version20260828224203 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages table ahead-of-time for Doctrine transport queues';
    }

    public function up(Schema $schema): void
    {
        // IF NOT EXISTS covers fresh DBs and existing transport DBs where the
        // table/index already exist without a recorded version row.
        $this->addSql('CREATE TABLE IF NOT EXISTS messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}
