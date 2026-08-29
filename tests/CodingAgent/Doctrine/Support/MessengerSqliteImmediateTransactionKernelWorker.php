<?php

declare(strict_types=1);

/**
 * Test-only CLI entry for kernel-backed messenger_transport SQLite probes.
 * Invoked from MessengerSqliteImmediateTransactionMiddlewareTest subprocesses.
 *
 * @internal
 */
require dirname(__DIR__, 4).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Tests\Doctrine\Support\MessengerSqliteImmediateTransactionKernelTestKernel;

$mode = $argv[1] ?? '';
if ('' === $mode) {
    fwrite(\STDERR, "usage: worker.php <mode> ...\n");
    exit(1);
}

$hatfieldCwd = getenv('HATFIELD_CWD');
if (!is_string($hatfieldCwd) || '' === $hatfieldCwd) {
    fwrite(\STDERR, "HATFIELD_CWD required\n");
    exit(1);
}

$_ENV['APP_ENV'] = 'test';
$_ENV['APP_DEBUG'] = '0';
$_ENV['APP_SECRET'] = 'test-secret';
$_ENV['HATFIELD_CWD'] = $hatfieldCwd;
putenv('APP_ENV=test');
putenv('HATFIELD_CWD='.$hatfieldCwd);

$testDb = getenv('HATFIELD_TEST_DATABASE_PATH');
if (is_string($testDb) && '' !== $testDb) {
    $_ENV['HATFIELD_TEST_DATABASE_PATH'] = $testDb;
    putenv('HATFIELD_TEST_DATABASE_PATH='.$testDb);
}
$transportDb = getenv('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH');
if (is_string($transportDb) && '' !== $transportDb) {
    $_ENV['HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] = $transportDb;
    putenv('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH='.$transportDb);
}

chdir($hatfieldCwd);

// Subprocess kernels disable DAMA static connections so each worker gets a fresh
// messenger_transport connection and real outer BEGIN IMMEDIATE transactions.
StaticDriver::setKeepStaticConnections(false);

MessengerSqliteImmediateTransactionKernelTestKernel::bootForSqliteWorker();
/** @var Connection $transport */
$transport = MessengerSqliteImmediateTransactionKernelTestKernel::getContainerForSqliteWorker()->get('doctrine.dbal.messenger_transport_connection');

try {
    match ($mode) {
        'nested-savepoint-probe' => nestedSavepointProbe($transport),
        'rollback-probe' => rollbackProbe($transport),

        default => throw new InvalidArgumentException('unknown mode: '.$mode),
    };
} catch (Throwable $e) {
    fwrite(\STDERR, $e->getMessage()."\n");
    exit(2);
}

exit(0);

function nestedSavepointProbe(Connection $transport): void
{
    $transport->beginTransaction();
    $transport->beginTransaction();
    try {
        $transport->executeStatement(
            'CREATE TABLE IF NOT EXISTS immediate_tx_probe (id INTEGER PRIMARY KEY)',
        );
        $transport->executeStatement('INSERT INTO immediate_tx_probe (id) VALUES (1)');
        $transport->commit();
        $transport->commit();
    } catch (Throwable $e) {
        while ($transport->isTransactionActive()) {
            $transport->rollBack();
        }
        throw $e;
    }

    $count = (int) $transport->fetchOne('SELECT COUNT(*) FROM immediate_tx_probe');
    if (1 !== $count) {
        throw new RuntimeException('nested savepoint probe failed');
    }
    $transport->executeStatement('DROP TABLE immediate_tx_probe');
}

function rollbackProbe(Connection $transport): void
{
    $transport->beginTransaction();
    try {
        $transport->executeStatement(
            'CREATE TABLE IF NOT EXISTS rollback_probe (id INTEGER PRIMARY KEY)',
        );
        $transport->rollBack();
    } catch (Throwable $e) {
        $transport->rollBack();
        throw $e;
    }

    $exists = (int) $transport->fetchOne(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'rollback_probe'",
    );
    if (0 !== $exists) {
        throw new RuntimeException('rollback probe failed');
    }
}
