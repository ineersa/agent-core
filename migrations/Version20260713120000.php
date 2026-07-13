<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\UuidV7;

final class Version20260713120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add immutable provider_cache_key (UUIDv7) to hatfield_session with backfill';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE hatfield_session ADD COLUMN provider_cache_key VARCHAR(36) DEFAULT NULL');

        $ids = $this->connection->fetchFirstColumn('SELECT id FROM hatfield_session');
        foreach ($ids as $id) {
            $key = UuidV7::v7()->toRfc4122();
            $this->addSql(
                'UPDATE hatfield_session SET provider_cache_key = ? WHERE id = ?',
                [$key, $id],
            );
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_hatfield_session_provider_cache_key ON hatfield_session (provider_cache_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_hatfield_session_provider_cache_key');
        $this->addSql('ALTER TABLE hatfield_session DROP COLUMN provider_cache_key');
    }
}
