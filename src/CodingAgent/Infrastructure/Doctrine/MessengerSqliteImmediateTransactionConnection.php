<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * SQLite driver connection that starts outer transactions with BEGIN IMMEDIATE.
 *
 * Symfony Messenger's Doctrine transport uses BEGIN DEFERRED, SELECT, then UPDATE
 * to claim rows. Concurrent consumers can establish incompatible read snapshots and
 * fail on upgrade with SQLITE_BUSY before busy_timeout can help. Reserving the
 * writer slot up front makes contention wait at transaction start (busy_timeout).
 */
final class MessengerSqliteImmediateTransactionConnection extends AbstractConnectionMiddleware
{
    public function beginTransaction(): void
    {
        $this->exec('BEGIN IMMEDIATE');
    }
}
