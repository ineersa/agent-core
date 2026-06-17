<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create cache_items table for DBAL-backed cache pools (cache.app, cache.approvals)
 * ahead of consumer launch to prevent the auto-creation race when multiple
 * messenger processes boot simultaneously and race to CREATE TABLE.
 *
 * Symfony's DoctrineDbalCacheAdapterSchemaListener would auto-create this table
 * lazily, but when 5+ consumer processes (run_control, llm, tool x N, scheduler)
 * boot concurrently, they all attempt lazy CREATE TABLE — only the first succeeds
 * and the rest fail. By materializing the schema here via the StartupDatabaseMigrator
 * (which runs BEFORE any consumer), the auto-creation path finds the table already
 * present and skips DDL.
 *
 * The schema matches PdoAdapter::createTable() for SQLite exactly.
 *
 * @see \Symfony\Component\Cache\Adapter\PdoAdapter::createTable()
 * @see src/CodingAgent/Migrations/StartupDatabaseMigrator.php
 */
final class Version20260617141001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cache_items table ahead-of-time for DBAL-backed cache pools';
    }

    public function up(Schema $schema): void
    {
        // CREATE TABLE IF NOT EXISTS handles both fresh DBs (migration creates
        // the table first) and existing DBs where lazy auto-creation already ran.
        // SQLite schema per PdoAdapter: item_id TEXT PK, item_data BLOB,
        // item_lifetime INTEGER nullable, item_time INTEGER NOT NULL.
        $this->addSql('CREATE TABLE IF NOT EXISTS cache_items (
            item_id TEXT NOT NULL PRIMARY KEY,
            item_data BLOB NOT NULL,
            item_lifetime INTEGER DEFAULT NULL,
            item_time INTEGER NOT NULL
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cache_items');
    }
}
