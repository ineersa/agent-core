<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Infrastructure\Doctrine\MessengerSqliteImmediateTransactionMiddleware;
use Ineersa\CodingAgent\Migrations\MessengerTransportSchemaEnsurer;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;

/**
 * Regression: Messenger transport SQLite claims use BEGIN IMMEDIATE on the
 * dedicated connection so a competing writer waits (busy_timeout) instead of
 * failing on deferred read→write upgrade under concurrent consumers.
 *
 * Kernel transport connections sit inside DAMA's outer test transaction; claim
 * semantics are exercised on fresh DriverManager connections with the same
 * middleware stack as production (middleware only, no DAMA).
 *
 * @requires extension pdo_sqlite
 *
 * @coversNothing
 */
final class MessengerSqliteImmediateTransactionMiddlewareTest extends IsolatedKernelTestCase
{
    private Connection $transportConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transportConnection = static::getContainer()->get('doctrine.dbal.messenger_transport_connection');
    }

    public function testMiddlewareIsRegisteredOnlyForMessengerTransportConnection(): void
    {
        /** @var Container $container */
        $container = static::getContainer();

        $this->assertTrue(
            $container->has('Ineersa\CodingAgent\Infrastructure\Doctrine\MessengerSqliteImmediateTransactionMiddleware.messenger_transport'),
            'BEGIN IMMEDIATE middleware must be wired for messenger_transport only',
        );
        $this->assertFalse(
            $container->has('Ineersa\CodingAgent\Infrastructure\Doctrine\MessengerSqliteImmediateTransactionMiddleware.default'),
            'default state.sqlite connection must not use BEGIN IMMEDIATE middleware',
        );
    }

    public function testImmediateMiddlewareConnectionSupportsNestedTransactionsViaSavepoints(): void
    {
        $connection = $this->freshImmediateTransportConnection();
        try {
            $connection->beginTransaction();
            $connection->beginTransaction();
            $connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS immediate_tx_probe (id INTEGER PRIMARY KEY)',
            );
            $connection->executeStatement('INSERT INTO immediate_tx_probe (id) VALUES (1)');
            $connection->commit();
            $connection->commit();

            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM immediate_tx_probe');
            $this->assertSame(1, $count);

            $connection->executeStatement('DROP TABLE immediate_tx_probe');
        } finally {
            $connection->close();
        }
    }

    public function testBeginImmediateWaitsForCompetingWriterThenCompletesClaimTransaction(): void
    {
        $transportPath = $this->sqliteFilePath($this->transportConnection);

        $seedConnection = $this->freshImmediateTransportConnection();
        (new MessengerTransportSchemaEnsurer($seedConnection, new NullLogger()))();

        $queueName = 'immediate_claim_'.bin2hex(random_bytes(8));
        $now = (new \DateTimeImmutable('UTC'))->format('Y-m-d H:i:s');

        $seedConnection->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at)
             VALUES (?, ?, ?, ?, ?, NULL)',
            ['body', '[]', $queueName, $now, $now],
        );
        $messageId = (int) $seedConnection->fetchOne(
            'SELECT id FROM messenger_messages WHERE queue_name = ? ORDER BY id DESC LIMIT 1',
            [$queueName],
        );
        $seedConnection->close();

        $barrierDir = TestDirectoryIsolation::createProjectTempDir('sqlite-immediate-barrier', 0o750);
        $readyPath = $barrierDir.'/ready';
        $donePath = $barrierDir.'/done';
        $holdMs = 200;

        try {
            $workerScript = $barrierDir.'/hold_writer.php';
            file_put_contents($workerScript, $this->holdWriterWorkerScript());

            $spec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $env = array_merge($_ENV, [
                'SQLITE_PATH' => $transportPath,
                'BARRIER_READY' => $readyPath,
                'BARRIER_DONE' => $donePath,
                'HOLD_MS' => (string) $holdMs,
            ]);
            $proc = proc_open(
                [\PHP_BINARY, $workerScript],
                $spec,
                $pipes,
                $barrierDir,
                $env,
            );
            $this->assertIsResource($proc, 'Writer-holding subprocess must start');

            fclose($pipes[0]);
            stream_set_blocking($pipes[2], false);

            $deadline = microtime(true) + 15.0;
            while (microtime(true) < $deadline) {
                if (is_file($readyPath)) {
                    break;
                }
                usleep(5000);
            }
            $this->assertFileExists($readyPath, 'Writer subprocess must signal BEGIN IMMEDIATE hold');

            $claimConnection = $this->freshImmediateTransportConnection();
            $started = microtime(true);
            try {
                $claimConnection->beginTransaction();
                try {
                    $row = $claimConnection->fetchAssociative(
                        'SELECT id FROM messenger_messages
                         WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= datetime(\'now\')
                         ORDER BY available_at ASC LIMIT 1',
                        [$queueName],
                    );
                    $this->assertIsArray($row);
                    $this->assertSame($messageId, (int) $row['id']);

                    $claimConnection->executeStatement(
                        'UPDATE messenger_messages SET delivered_at = ? WHERE id = ?',
                        [(new \DateTimeImmutable('UTC'))->format('Y-m-d H:i:s'), (string) $messageId],
                    );
                    $claimConnection->commit();
                } catch (\Throwable $e) {
                    $claimConnection->rollBack();
                    throw $e;
                }
            } finally {
                touch($donePath);
                $claimConnection->close();
            }

            $elapsedMs = (microtime(true) - $started) * 1000;
            $stderr = stream_get_contents($pipes[2]);
            $exit = proc_close($proc);
            $this->assertSame(0, $exit, 'Writer subprocess stderr: '.$stderr);
            $this->assertGreaterThanOrEqual(
                $holdMs * 0.5,
                $elapsedMs,
                'BEGIN IMMEDIATE on messenger transport should wait on writer contention',
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($barrierDir);
            $cleanup = $this->freshImmediateTransportConnection();
            try {
                $cleanup->executeStatement(
                    'DELETE FROM messenger_messages WHERE queue_name = ?',
                    [$queueName],
                );
            } finally {
                $cleanup->close();
            }
        }
    }

    public function testImmediateMiddlewareConnectionRollBackReleasesOuterTransaction(): void
    {
        $connection = $this->freshImmediateTransportConnection();
        try {
            $connection->beginTransaction();
            $connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS rollback_probe (id INTEGER PRIMARY KEY)',
            );
            $connection->rollBack();

            $exists = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'rollback_probe'",
            );
            $this->assertSame(0, $exists);
        } finally {
            $connection->close();
        }
    }

    private function freshImmediateTransportConnection(): Connection
    {
        $params = $this->transportConnection->getParams();
        $path = $params['path'] ?? null;
        $this->assertIsString($path);

        $config = new Configuration();
        $config->setMiddlewares([new MessengerSqliteImmediateTransactionMiddleware()]);

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ], $config);
    }

    private function sqliteFilePath(Connection $connection): string
    {
        $params = $connection->getParams();
        $path = $params['path'] ?? null;
        $this->assertIsString($path);

        return $path;
    }

    private function holdWriterWorkerScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$path = getenv('SQLITE_PATH');
$ready = getenv('BARRIER_READY');
$done = getenv('BARRIER_DONE');
$holdMs = (int) (getenv('HOLD_MS') ?: '200');
if (!is_string($path) || $path === '' || !is_string($ready) || !is_string($done)) {
    fwrite(STDERR, "missing env\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_TIMEOUT => 5]);
$pdo->exec('BEGIN IMMEDIATE');
touch($ready);
$until = microtime(true) + ($holdMs / 1000);
while (!is_file($done) && microtime(true) < $until) {
    usleep(2000);
}
$pdo->exec('COMMIT');
exit(0);
PHP;
    }
}
