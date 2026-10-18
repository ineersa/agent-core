<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Amp\Parallel\Context\ProcessContextFactory;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Ineersa\CodingAgent\Runtime\Messenger\InternalSqliteWorkerBootstrap;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueConnectorFactory;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueConnectorFactory
 */
final class SessionSqliteQueueConnectorFactoryTest extends TestCase
{
    public function testFactoryInjectsExecutableCommandPlusInternalWorkerFlag(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('sqlite-connector-factory');
        try {
            $locator = $this->createStub(AppExecutableLocator::class);
            $locator->method('path')->willReturn($dir.'/bin/console');
            $locator->method('command')->willReturn([\PHP_BINARY, $dir.'/bin/console']);
            $factory = new SessionSqliteQueueConnectorFactory(new RuntimeProcessConfig($locator, $dir));
            $connector = $factory();
            $this->assertInstanceOf(SqliteConnector::class, $connector);

            $contextFactoryProperty = new \ReflectionProperty(SqliteConnector::class, 'contextFactory');
            $contextFactory = $contextFactoryProperty->getValue($connector);
            $this->assertInstanceOf(ProcessContextFactory::class, $contextFactory);

            $binaryProperty = new \ReflectionProperty(ProcessContextFactory::class, 'binary');
            $binary = $binaryProperty->getValue($contextFactory);
            $this->assertSame([
                \PHP_BINARY,
                $dir.'/bin/console',
                InternalSqliteWorkerBootstrap::FLAG,
            ], $binary);

            $cwdProperty = new \ReflectionProperty(ProcessContextFactory::class, 'workingDirectory');
            $this->assertSame($dir, $cwdProperty->getValue($contextFactory));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
