<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * Regression: Messenger transport SQLite claims use BEGIN IMMEDIATE on the
 * dedicated messenger_transport connection so a competing writer waits at
 * beginTransaction() (busy_timeout) instead of failing on deferred read→write upgrade.
 *
 * Subprocess helpers boot APP_ENV=test and resolve doctrine.dbal.messenger_transport_connection
 * from the container. StaticDriver::setKeepStaticConnections(false) in those subprocesses
 * avoids DAMA's static outer transaction so production outer BEGIN IMMEDIATE semantics
 * can be exercised (in-process kernel connections stay under DAMA in the parent test).
 *
 * @requires extension pdo_sqlite
 *
 * @coversNothing
 */
final class MessengerSqliteImmediateTransactionMiddlewareTest extends IsolatedKernelTestCase
{
    private Connection $defaultConnection;

    private string $workerScript;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->workerScript = ProjectDir::get().'/tests/CodingAgent/Doctrine/Support/MessengerSqliteImmediateTransactionKernelWorker.php';
    }

    public function testMiddlewareIsRegisteredOnlyForMessengerTransportConnection(): void
    {
        /** @var Container $container */
        $container = static::getContainer();

        $this->assertTrue(
            $container->has('Ineersa\\CodingAgent\\Infrastructure\\Doctrine\\MessengerSqliteImmediateTransactionMiddleware.messenger_transport'),
            'BEGIN IMMEDIATE middleware must be wired for messenger_transport only',
        );
        $this->assertFalse(
            $container->has('Ineersa\\CodingAgent\\Infrastructure\\Doctrine\\MessengerSqliteImmediateTransactionMiddleware.default'),
            'default state.sqlite connection must not use BEGIN IMMEDIATE middleware',
        );
    }

    public function testDefaultConnectionUsesDeferredBeginNotImmediateMiddleware(): void
    {
        $this->defaultConnection->beginTransaction();
        try {
            $this->defaultConnection->executeStatement(
                'CREATE TABLE IF NOT EXISTS default_tx_probe (id INTEGER PRIMARY KEY)',
            );
            $this->defaultConnection->executeStatement('INSERT INTO default_tx_probe (id) VALUES (1)');
            $this->defaultConnection->commit();
        } catch (\Throwable $e) {
            $this->defaultConnection->rollBack();
            throw $e;
        }

        $count = (int) $this->defaultConnection->fetchOne('SELECT COUNT(*) FROM default_tx_probe');
        $this->assertSame(1, $count);
        $this->defaultConnection->executeStatement('DROP TABLE default_tx_probe');
    }

    public function testTransportConnectionSupportsNestedTransactionsViaSavepoints(): void
    {
        $this->runKernelWorker(['nested-savepoint-probe']);
    }

    public function testTransportConnectionRollBackReleasesOuterTransaction(): void
    {
        $this->runKernelWorker(['rollback-probe']);
    }

    public function testBeginImmediateWaitsForCompetingWriterThenCompletesClaimTransaction(): void
    {
        $barrierDir = TestDirectoryIsolation::createProjectTempDir('sqlite-immediate-barrier', 0o750);
        $queueName = 'immediate_claim_'.bin2hex(random_bytes(8));
        $messageIdPath = $barrierDir.'/message_id';
        $readyPath = $barrierDir.'/ready';
        $releasePath = $barrierDir.'/release';
        $acquiredPath = $barrierDir.'/acquired';
        $holdMs = 400;

        $holderProc = null;
        $holderPipes = null;
        $claimProc = null;
        $claimPipes = null;

        try {
            $this->runKernelWorker(['seed-message', $queueName, $messageIdPath]);
            $this->assertFileExists($messageIdPath);

            $holderProc = $this->startKernelWorkerProcess(
                ['hold-writer', $readyPath, $releasePath, (string) $holdMs],
                $holderPipes,
            );
            $this->waitForFile($readyPath, 15.0, 'Writer subprocess must signal outer transaction hold');
            usleep(50_000);

            $claimProc = $this->startKernelWorkerProcess(
                ['claim-message', $queueName, $messageIdPath, $acquiredPath],
                $claimPipes,
            );
            $claimExit = $this->waitForProcessExit($claimProc, $claimPipes, 20.0);
            $holderExit = $this->waitForProcessExit($holderProc, $holderPipes, 20.0);

            $this->assertSame(0, $claimExit['exit'], 'claim worker stderr: '.$claimExit['stderr']);
            $this->assertSame(0, $holderExit['exit'], 'hold worker stderr: '.$holderExit['stderr']);
            $this->assertFileExists($acquiredPath);

            /** @var array{begin_elapsed_ms: float, claim_elapsed_ms: float} $acquired */
            $acquired = json_decode((string) file_get_contents($acquiredPath), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertGreaterThanOrEqual(
                $holdMs * 0.35,
                $acquired['begin_elapsed_ms'],
                'Writer contention must block at beginTransaction()/BEGIN IMMEDIATE, not only at later UPDATE',
            );
            $this->assertLessThan(
                $acquired['begin_elapsed_ms'] + 50.0,
                $acquired['claim_elapsed_ms'],
                'Post-begin claim work should be fast relative to begin wait',
            );
        } finally {
            if (\is_resource($claimProc)) {
                @proc_terminate($claimProc);
                @proc_close($claimProc);
            }
            if (\is_resource($holderProc)) {
                @touch($releasePath);
                @proc_terminate($holderProc);
                @proc_close($holderProc);
            }
            $this->runKernelWorker(['delete-queue', $queueName]);
            TestDirectoryIsolation::removeDirectory($barrierDir);
        }
    }

    /**
     * @param list<string> $args
     */
    private function runKernelWorker(array $args): void
    {
        $result = $this->runKernelWorkerProcess($args);
        $this->assertSame(0, $result['exit'], 'kernel worker stderr: '.$result['stderr']);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stderr: string}
     */
    private function runKernelWorkerProcess(array $args): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge([\PHP_BINARY, $this->workerScript], $args),
            $spec,
            $pipes,
            ProjectDir::get(),
            $this->kernelWorkerEnv(),
        );
        $this->assertIsResource($proc, 'kernel worker must start');

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $this->waitForProcessExit($proc, $pipes, 60.0);
    }

    /**
     * @param list<string>              $args
     * @param array<int, resource>|null $pipes
     *
     * @return resource
     */
    private function startKernelWorkerProcess(array $args, ?array &$pipes)
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge([\PHP_BINARY, $this->workerScript], $args),
            $spec,
            $pipes,
            ProjectDir::get(),
            $this->kernelWorkerEnv(),
        );
        $this->assertIsResource($proc, 'kernel worker subprocess must start');
        fclose($pipes[0]);
        stream_set_blocking($pipes[2], false);

        return $proc;
    }

    /**
     * @param resource             $proc
     * @param array<int, resource> $pipes
     *
     * @return array{exit: int, stderr: string}
     */
    private function waitForProcessExit($proc, array $pipes, float $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $stderr = '';
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($proc);

                return ['exit' => $exit, 'stderr' => $stderr];
            }
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            usleep(5000);
        }

        proc_terminate($proc);
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stderr' => $stderr];
    }

    private function waitForFile(string $path, float $timeoutSeconds, string $message): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return;
            }
            usleep(5000);
        }
        $this->fail($message);
    }

    /**
     * @return array<string, string>
     */
    private function kernelWorkerEnv(): array
    {
        $env = array_merge($_ENV, [
            'APP_ENV' => 'test',
            'HATFIELD_CWD' => $this->isolatedCwd(),
        ]);
        foreach (['HATFIELD_TEST_DATABASE_PATH', 'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH'] as $key) {
            $value = getenv($key);
            if (\is_string($value) && '' !== $value) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
