<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Production OM SQLite connection factory (extension-owned DB only).
 *
 * Intentionally constructs a standalone DBAL connection per job so OM never
 * shares Hatfield's Doctrine connection/pool. The extension_agent worker may
 * process jobs from many projects; path + PRAGMA setup is therefore job-local.
 */
final class OmDatabaseFactory
{
    public static function connect(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        $logger ??= new NullLogger();
        $dir = \dirname($databasePath);
        // Owner-only directory: om.sqlite holds conversation-derived memory.
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create OM data directory: %s', $dir));
        }

        // DBAL 4 requires explicit driver+path for SQLite (URL-only fails DriverRequired).
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $databasePath,
        ]);

        OmSqliteConnectionConfigurator::configure($connection);

        $logger->info('om.database.connected', [
            'component' => 'observational_memory',
            'event_type' => 'om.database.connected',
        ]);

        return $connection;
    }

    public static function connectAndMigrate(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        $logger ??= new NullLogger();
        $connection = self::connect($databasePath, $logger);
        (new OmSchemaMigrator($connection, $logger))->migrate();

        return $connection;
    }
}
