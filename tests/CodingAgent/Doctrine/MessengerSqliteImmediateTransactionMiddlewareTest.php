<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Doctrine;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
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
    private string $workerScript;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerScript = ProjectDir::get().'/tests/CodingAgent/Doctrine/Support/MessengerSqliteImmediateTransactionKernelWorker.php';
    }

    public function testMiddlewareIsRegisteredOnlyForMessengerTransportConnection(): void
    {
        /** @var Container $container */
        $container = static::getContainer();

        // Container child service ids prove DoctrineBundle scoped the middleware tag to messenger_transport only.
        $this->assertTrue(
            $container->has('Ineersa\\CodingAgent\\Infrastructure\\Doctrine\\MessengerSqliteImmediateTransactionMiddleware.messenger_transport'),
            'BEGIN IMMEDIATE middleware must be wired for messenger_transport only',
        );
        $this->assertFalse(
            $container->has('Ineersa\\CodingAgent\\Infrastructure\\Doctrine\\MessengerSqliteImmediateTransactionMiddleware.default'),
            'default state.sqlite connection must not use BEGIN IMMEDIATE middleware',
        );
    }

    public function testTransportConnectionSupportsNestedTransactionsViaSavepoints(): void
    {
        $this->runKernelWorker(['nested-savepoint-probe']);
    }

    public function testTransportConnectionRollBackReleasesOuterTransaction(): void
    {
        $this->runKernelWorker(['rollback-probe']);
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
        $proc = $this->openKernelWorkerProcess($args, $pipes, 'kernel worker must start');
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
    private function openKernelWorkerProcess(array $args, ?array &$pipes, string $startFailureMessage)
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
        $this->assertIsResource($proc, $startFailureMessage);
        fclose($pipes[0]);

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
                stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($proc);

                return ['exit' => $exit, 'stderr' => $stderr];
            }
            stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            usleep(5000);
        }

        proc_terminate($proc);
        stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stderr' => $stderr];
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
