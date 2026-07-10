<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-run monotonic event sequence allocator state.
 */
final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hatfield_run_sequence table for DB-backed event seq allocation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hatfield_run_sequence (run_id VARCHAR(255) NOT NULL, last_seq BIGINT NOT NULL, PRIMARY KEY(run_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE hatfield_run_sequence');
    }
}
