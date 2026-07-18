<?php

declare(strict_types=1);

/**
 * Test-only CLI entry for kernel-backed SQLite contention helpers.
 * Invoked from MessengerSqliteImmediateTransactionMiddlewareTest subprocesses.
 *
 * @internal
 */
require dirname(__DIR__, 4).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Migrations\MessengerTransportSchemaEnsurer;
use Ineersa\CodingAgent\Tests\Doctrine\Support\MessengerSqliteImmediateTransactionKernelTestKernel;
use Psr\Log\NullLogger;

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
        'seed-message' => seedMessage($transport, $argv[2] ?? '', $argv[3] ?? ''),
        'hold-writer' => holdWriter($transport, $argv[2] ?? '', $argv[3] ?? '', (int) ($argv[4] ?? '200')),
        'claim-message' => claimMessage($transport, $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
        'delete-queue' => deleteQueue($transport, $argv[2] ?? ''),
        'nested-savepoint-probe' => nestedSavepointProbe($transport),
        'rollback-probe' => rollbackProbe($transport),

        default => throw new InvalidArgumentException('unknown mode: '.$mode),
    };
} catch (Throwable $e) {
    fwrite(\STDERR, $e->getMessage()."\n");
    exit(2);
}

exit(0);

function ensureSchema(Connection $transport): void
{
    (new MessengerTransportSchemaEnsurer($transport, new NullLogger()))();
}

function seedMessage(Connection $transport, string $queueName, string $messageIdPath): void
{
    if ('' === $queueName || '' === $messageIdPath) {
        throw new InvalidArgumentException('seed-message requires queue and message-id path');
    }
    ensureSchema($transport);
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $transport->executeStatement(
        'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
         VALUES (?, ?, ?, ?, ?, NULL)',
        ['body', '[]', $queueName, $now, $now],
    );
    $messageId = (int) $transport->fetchOne(
        'SELECT id FROM messenger_messages WHERE queue_name = ? ORDER BY id DESC LIMIT 1',
        [$queueName],
    );
    file_put_contents($messageIdPath, (string) $messageId);
}

function holdWriter(Connection $transport, string $readyPath, string $releasePath, int $holdMs): void
{
    if ('' === $readyPath || '' === $releasePath) {
        throw new InvalidArgumentException('hold-writer requires ready and release paths');
    }
    // BEGIN IMMEDIATE reserves the SQLite writer slot; no durable DDL needed for the barrier.
    $transport->beginTransaction();
    touch($readyPath);
    $deadline = microtime(true) + max(1, $holdMs) / 1000;
    while (!is_file($releasePath) && microtime(true) < $deadline) {
        usleep(2000);
    }
    $transport->commit();
}

function claimMessage(Connection $transport, string $queueName, string $expectedIdPath, string $acquiredPath): void
{
    if ('' === $queueName || '' === $expectedIdPath || '' === $acquiredPath) {
        throw new InvalidArgumentException('claim-message requires queue, expected-id path, acquired path');
    }
    $expectedId = (int) trim((string) file_get_contents($expectedIdPath));
    $claimStarted = microtime(true);
    $beginStarted = microtime(true);
    $transport->beginTransaction();
    $beginElapsedMs = (microtime(true) - $beginStarted) * 1000;
    try {
        $row = $transport->fetchAssociative(
            'SELECT id FROM messenger_messages
             WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= datetime(\'now\')
             ORDER BY available_at ASC LIMIT 1',
            [$queueName],
        );
        if (!is_array($row)) {
            throw new RuntimeException('no claimable row');
        }
        $messageId = (int) $row['id'];
        if ($messageId !== $expectedId) {
            throw new RuntimeException(sprintf('unexpected message id %d', $messageId));
        }
        $transport->executeStatement(
            'UPDATE messenger_messages SET delivered_at = ? WHERE id = ?',
            [(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'), (string) $messageId],
        );
        $transport->commit();
    } catch (Throwable $e) {
        $transport->rollBack();
        throw $e;
    }
    $claimElapsedMs = (microtime(true) - $claimStarted) * 1000;
    file_put_contents(
        $acquiredPath,
        json_encode(
            ['begin_elapsed_ms' => $beginElapsedMs, 'claim_elapsed_ms' => $claimElapsedMs],
            \JSON_THROW_ON_ERROR,
        ),
    );
}

function nestedSavepointProbe(Connection $transport): void
{
    ensureSchema($transport);
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
    ensureSchema($transport);
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

function deleteQueue(Connection $transport, string $queueName): void
{
    if ('' === $queueName) {
        return;
    }
    $transport->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
}
