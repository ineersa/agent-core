<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/**
 * SQLite driver connection that starts outer transactions with BEGIN IMMEDIATE.
 *
 * Doctrine transactions commonly read before writing. Concurrent workers can then
 * establish incompatible SQLite WAL snapshots and fail on upgrade with SQLITE_BUSY
 * before busy_timeout can help. Reserving the writer slot up front makes contention
 * wait at transaction start instead.
 */
final class SqliteImmediateTransactionConnection extends AbstractConnectionMiddleware
{
    public function beginTransaction(): void
    {
        $this->exec('BEGIN IMMEDIATE');
    }
}
