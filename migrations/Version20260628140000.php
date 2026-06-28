<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add indexes for background_process lookup fields.
 *
 * Indexes:
 * - idx_bg_process_pid: supports findOneBy(['pid' => $pid]) in ProcessStore,
 *   markStoppedByUser, markBackgrounded, markCompletionNotified.
 * - idx_bg_process_session_id: supports session-scoped queries
 *   (fetchAll, findUnfinished, findPendingNotifications).
 * - idx_bg_process_finished_at: supports IS NULL/range conditions in
 *   findUnfinished, findPendingNotifications, findStale.
 */
final class Version20260628140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes to background_process for pid, session_id, finished_at';
    }

    public function up(Schema $schema): void
    {
        // Plain CREATE INDEX works here because Doctrine Migrations
        // guarantees each migration runs exactly once. SQLite does not
        // support IF NOT EXISTS for indexes, but the one-time application
        // guarantee makes it unnecessary.
        $this->addSql('CREATE INDEX idx_bg_process_pid ON background_process (pid)');
        $this->addSql('CREATE INDEX idx_bg_process_session_id ON background_process (session_id)');
        $this->addSql('CREATE INDEX idx_bg_process_finished_at ON background_process (finished_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bg_process_pid');
        $this->addSql('DROP INDEX idx_bg_process_session_id');
        $this->addSql('DROP INDEX idx_bg_process_finished_at');
    }
}
