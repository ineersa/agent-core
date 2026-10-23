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
use Symfony\Component\Uid\UuidV7;

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

    public function testDefaultStartupPathDropsLegacyReasoningBaselineAndOmitsItOnFreshSchema(): void
    {
        /** @var SessionCatalogRecoveryService $catalogRecovery */
        $catalogRecovery = static::getContainer()->get(SessionCatalogRecoveryService::class);
        $this->assertInstanceOf(
            ApplicationMigrationExecutor::class,
            static::getContainer()->get(ApplicationMigrationExecutor::class),
        );
        $knownMigrations = (new \ReflectionClass(ApplicationMigrationExecutor::class))
            ->getConstant('KNOWN_MIGRATIONS');
        $this->assertContains(
            \DoctrineMigrations\Version20261023000232::class,
            $knownMigrations,
            'DI-wired startup executor must register Version20261023000232',
        );
        $this->assertSame(
            \DoctrineMigrations\Version20261023000232::class,
            $knownMigrations[array_key_last($knownMigrations)],
        );

        $freshConnection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->isolatedDir.'/fresh-startup.sqlite',
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        try {
            $freshMigrator = new StartupDatabaseMigrator(
                new ApplicationMigrationExecutor($freshConnection, new NullLogger()),
                $catalogRecovery,
                new NullLogger(),
            );
            $freshMigrator();

            $freshColumns = $freshConnection->createSchemaManager()->listTableColumns('hatfield_session');
            $this->assertArrayNotHasKey('reasoning_baseline', $freshColumns);
            $this->assertNotFalse($freshConnection->fetchOne(
                'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
                ['Version20261023000232'],
            ));
        } finally {
            $freshConnection->close();
        }

        $upgradeConnection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->isolatedDir.'/legacy-startup.sqlite',
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        try {
            $now = '2026-10-22 00:00:00';
            $providerCacheKey = UuidV7::v7()->toRfc4122();
            $upgradeConnection->executeStatement(<<<'SQL'
CREATE TABLE doctrine_migration_versions (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    executed_at DATETIME DEFAULT NULL,
    execution_time INTEGER DEFAULT NULL
)
SQL);
            foreach ([
                'Version20260601152619',
                'Version20260606140000',
                'Version20260607000000',
                'Version20260608162000',
                'Version20260617141001',
                'Version20260617141002',
                'Version20260628140000',
                'Version20260710120000',
                'Version20260713120000',
                'Version20260713130000',
                'Version20260713140000',
                'Version20260713160000',
                'Version20260714140000',
                'Version20260715120000',
                'Version20260720120000',
                'Version20260723230000',
                'Version20260813031629',
                'Version20260828223857',
                'Version20260901015440',
                'Version20260901163503',
                'Version20260908010000',
                'Version20261004192458',
                'Version20261008200352',
                'Version20261019185942',
                'Version20261020210924',
                'Version20261021034317',
                'Version20261022235428',
            ] as $version) {
                $upgradeConnection->insert('doctrine_migration_versions', [
                    'version' => $version,
                    'executed_at' => $now,
                    'execution_time' => 0,
                ]);
            }

            $upgradeConnection->executeStatement(<<<'SQL'
CREATE TABLE hatfield_session (
    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    cwd VARCHAR(255) NOT NULL,
    prompt VARCHAR(255) DEFAULT NULL,
    parent_id VARCHAR(255) DEFAULT NULL,
    root_id VARCHAR(255) DEFAULT NULL,
    model VARCHAR(255) DEFAULT NULL,
    model_provider VARCHAR(255) DEFAULT NULL,
    model_name VARCHAR(255) DEFAULT NULL,
    reasoning VARCHAR(255) DEFAULT NULL,
    reasoning_baseline CLOB DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    name VARCHAR(200) DEFAULT '' NOT NULL,
    provider_cache_key VARCHAR(36) DEFAULT NULL
)
SQL);
            $upgradeConnection->executeStatement(
                'CREATE UNIQUE INDEX uniq_hatfield_session_provider_cache_key ON hatfield_session (provider_cache_key)'
            );
            $upgradeConnection->insert('hatfield_session', [
                'id' => 96,
                'cwd' => '/legacy/session',
                'prompt' => 'keep-me',
                'parent_id' => null,
                'root_id' => null,
                'model' => 'chatgpt/gpt-5',
                'model_provider' => 'chatgpt',
                'model_name' => 'gpt-5',
                'reasoning' => 'high',
                'reasoning_baseline' => '{"model":"chatgpt/gpt-5","effort":"medium"}',
                'created_at' => $now,
                'updated_at' => $now,
                'name' => 'legacy-session',
                'provider_cache_key' => $providerCacheKey,
            ]);

            $upgradeMigrator = new StartupDatabaseMigrator(
                new ApplicationMigrationExecutor($upgradeConnection, new NullLogger()),
                $catalogRecovery,
                new NullLogger(),
            );
            $upgradeMigrator();

            $upgradeColumns = $upgradeConnection->createSchemaManager()->listTableColumns('hatfield_session');
            $this->assertArrayNotHasKey('reasoning_baseline', $upgradeColumns);
            $row = $upgradeConnection->fetchAssociative(
                'SELECT id, reasoning, provider_cache_key, model FROM hatfield_session'
            );
            $this->assertIsArray($row);
            $this->assertSame(96, (int) $row['id']);
            $this->assertSame('high', $row['reasoning']);
            $this->assertSame($providerCacheKey, $row['provider_cache_key']);
            $this->assertSame('chatgpt/gpt-5', $row['model']);
            $this->assertNotFalse($upgradeConnection->fetchOne(
                'SELECT 1 FROM doctrine_migration_versions WHERE version = ?',
                ['Version20261023000232'],
            ));
        } finally {
            $upgradeConnection->close();
        }
    }
}
