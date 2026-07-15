<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\UuidV7;

/**
 * Repair hatfield_session rows left with NULL or empty provider_cache_key after
 * Version20260713120000. Startup ApplicationMigrationExecutor invokes up() but
 * only replays addSql() statements without query parameters, so the original
 * parameterized backfill may not have applied; this migration repairs via
 * connection executeStatement inside up().
 */
final class Version20260715120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill missing hatfield_session.provider_cache_key (NULL or empty) with distinct UUIDv7 values';
    }

    public function up(Schema $schema): void
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM hatfield_session WHERE provider_cache_key IS NULL OR provider_cache_key = ?',
            [''],
        );

        foreach ($ids as $id) {
            $key = UuidV7::v7()->toRfc4122();
            $this->connection->executeStatement(
                'UPDATE hatfield_session SET provider_cache_key = ? WHERE id = ? AND (provider_cache_key IS NULL OR provider_cache_key = ?)',
                [$key, $id, ''],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Data repair migration: assigned UUIDv7 values cannot be reverted safely.
    }
}
