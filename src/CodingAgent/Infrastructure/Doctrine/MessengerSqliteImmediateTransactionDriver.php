<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class MessengerSqliteImmediateTransactionDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $wrappedDriver)
    {
        parent::__construct($wrappedDriver);
    }

    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);

        return new MessengerSqliteImmediateTransactionConnection($connection);
    }
}
