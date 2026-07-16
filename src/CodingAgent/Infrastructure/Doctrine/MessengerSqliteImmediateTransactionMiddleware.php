<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL driver middleware for the dedicated Messenger transport SQLite connection.
 *
 * Registered only on connection name messenger_transport (see services.yaml).
 * The default state.sqlite connection keeps PDO deferred BEGIN semantics.
 */
final class MessengerSqliteImmediateTransactionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new MessengerSqliteImmediateTransactionDriver($driver);
    }
}
