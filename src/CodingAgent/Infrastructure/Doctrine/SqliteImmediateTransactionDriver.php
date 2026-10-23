<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class SqliteImmediateTransactionDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);
        // synchronous is connection-local: configure every worker and reconnect
        // before any transaction, not only the process that migrates the schema.
        $connection->exec('PRAGMA synchronous = NORMAL');

        return new SqliteImmediateTransactionConnection($connection);
    }
}
