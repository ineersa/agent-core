<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL driver middleware for the dedicated Messenger transport SQLite connection.
 *
 * Intentionally attached only to the dedicated messenger_transport SQLite connection
 * (see services.yaml connection tag). The default state.sqlite connection keeps deferred BEGIN.
 */
final class MessengerSqliteImmediateTransactionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new MessengerSqliteImmediateTransactionDriver($driver);
    }
}
