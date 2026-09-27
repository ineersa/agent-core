<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Ineersa\CodingAgent\Infrastructure\Doctrine\SqlitePollingConnection;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SqlitePollingConnectionTest extends TestCase
{
    public function testStalePositiveAvailabilityStillRechecksInsideTransaction(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $db->method('createQueryBuilder')->willReturnCallback(static fn (): QueryBuilder => new QueryBuilder($db));
        $count = $this->createMock(Result::class);
        $count->expects($this->once())->method('fetchOne')->willReturn(1);
        $claimedElsewhere = $this->createMock(Result::class);
        $claimedElsewhere->expects($this->once())->method('fetchAllAssociative')->willReturn([]);
        $began = false;
        $db->expects($this->once())->method('beginTransaction')->willReturnCallback(static function () use (&$began): void {
            $began = true;
        });
        $queries = 0;
        $db->expects($this->exactly(2))->method('executeQuery')->willReturnCallback(function () use (&$queries, &$began, $count, $claimedElsewhere): Result {
            if (0 === $queries++) {
                $this->assertFalse($began);

                return $count;
            }
            $this->assertTrue($began, 'A stale positive must trigger a new read under the writer transaction.');

            return $claimedElsewhere;
        });
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('executeStatement');

        $connection = new SqlitePollingConnection(['auto_setup' => false], $db);
        $this->assertNull($connection->get(4));
    }

    public function testKernelTransportSkipsEmptyTransactionsAndPreservesClaims(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('sqlite-polling');
        TestDirectoryIsolation::createHatfieldTree($directory);
        $dbDirectory = '../tmp/'.basename($directory);
        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'HATFIELD_SESSION_ID' => false,
            'HATFIELD_CWD' => $directory,
            'HATFIELD_LOG_DIR' => $directory.'/logs',
            'HATFIELD_TEST_DATABASE_PATH' => $dbDirectory.'/state.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $dbDirectory.'/transport.sqlite',
        ];
        $process = new Process([
            \PHP_BINARY,
            ProjectDir::get().'/tests/CodingAgent/Doctrine/Support/SqlitePollingKernelWorker.php',
        ], $directory, $env, timeout: 8.0);
        try {
            $process->mustRun();
            $data = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame(0, $data['empty_count']);
            $this->assertSame(2, $data['batch_count']);
            $this->assertSame(0, $data['reserved_count']);
            $this->assertSame(0, $data['delayed_count']);
            $this->assertSame(0, $data['other_queue_count']);
            $this->assertSame(1, $data['expired_count']);
            $this->assertSame(1, $data['new_arrival_count']);
            $this->assertSame(0, $data['rows_after_ack_reject']);
            $this->assertSame('missing_table_propagated', $data['missing_table']);
            $this->assertSame(0, $data['auto_setup_count']);

            $this->assertContains('BEGIN IMMEDIATE', $data['statements']['stock_empty']);
            foreach (['optimized_empty', 'reserved', 'delayed', 'other_queue'] as $name) {
                $this->assertCount(1, $data['statements'][$name], $name);
                $this->assertStringStartsWith('SELECT COUNT(', $data['statements'][$name][0]);
            }
            foreach (['batch', 'expired', 'new_arrival'] as $name) {
                $this->assertContains('BEGIN IMMEDIATE', $data['statements'][$name], $name);
                $this->assertContains('Committing transaction', $data['statements'][$name], $name);
            }
        } finally {
            $process->stop(0.1);
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
