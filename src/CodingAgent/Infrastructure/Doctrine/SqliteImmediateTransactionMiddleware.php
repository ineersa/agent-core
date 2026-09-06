<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL driver middleware for SQLite connections shared by concurrent workers.
 *
 * Registration stays explicit and connection-scoped in services.yaml.
 */
final class SqliteImmediateTransactionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new SqliteImmediateTransactionDriver($driver);
    }
}
