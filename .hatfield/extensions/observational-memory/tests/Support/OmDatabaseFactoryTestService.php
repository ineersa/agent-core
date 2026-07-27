<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

use Doctrine\DBAL\Connection;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TEST-ONLY wrapper around production OmDatabaseFactory for container access.
 *
 * Registered only in config/services_test.yaml. Does not live in production src.
 */
final class OmDatabaseFactoryTestService
{
    public function connect(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        return OmDatabaseFactory::connect($databasePath, $logger ?? new NullLogger());
    }

    public function connectAndMigrate(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        return OmDatabaseFactory::connectAndMigrate($databasePath, $logger ?? new NullLogger());
    }
}
