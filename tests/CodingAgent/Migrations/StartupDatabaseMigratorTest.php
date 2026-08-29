<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Migrations;

use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Migrations\ApplicationMigrationExecutor;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Session\SessionCatalogRecoveryService;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;

final class StartupDatabaseMigratorTest extends IsolatedKernelTestCase
{
    private string $isolatedDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->isolatedDir = TestDirectoryIsolation::createProjectTempDir('startup-migrator-test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->isolatedDir);
        parent::tearDown();
    }

    public function testMigrationFailurePropagatesToStopStartup(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->isolatedDir.'/failure.sqlite',
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        $failingExecutor = new ApplicationMigrationExecutor(
            $connection,
            new NullLogger(),
            [\stdClass::class],
        );
        /** @var SessionCatalogRecoveryService $catalogRecovery */
        $catalogRecovery = static::getContainer()->get(SessionCatalogRecoveryService::class);
        $migrator = new StartupDatabaseMigrator(
            $failingExecutor,
            $failingExecutor,
            $catalogRecovery,
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to run database migrations');

        try {
            $migrator();
        } finally {
            $connection->close();
        }
    }
}
