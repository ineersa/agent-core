<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Support\OwnedProcessTree;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Thesis: controller SIGKILL must close the supervised broker owner pipe so the
 * broker and Amp helper exit and release durable queue lifetime locks. A
 * replacement controller for the same session DB must then reach runtime.ready.
 *
 * Unique process contract: literal controller hard death cannot be proved by
 * closing an InputStream in-process. Timeout below is a safety cap around
 * positive readiness/exit predicates.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerBrokerHardDeathProcessTest extends ControllerReplayE2eTestCase
{
    protected function tearDown(): void
    {
        $this->stopProcess();
        $this->assertNoTrackedControllerProcessSurvivors();
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        \PHPUnit\Framework\TestCase::tearDown();
    }

    public function testControllerSigkillStopsOwnedBrokerHelperAndAllowsDurableDbRestart(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $controllerPid = $this->controllerRootPid();
        $this->assertGreaterThan(0, $controllerPid);
        $this->assertTrue($this->isSameUidProcess($controllerPid));
        $this->assertFalse(
            $this->processEnvironContains($controllerPid, 'HATFIELD_SESSION_ID='),
            'harness controller must stay untagged for teardown safety',
        );

        $broker = $this->waitForOwnedBroker($controllerPid, 5.0);
        $this->assertNotNull($broker, 'supervised sqlite-queue:broker must exist after runtime.ready');
        $brokerPid = $broker['pid'];
        $driverTree = OwnedProcessTree::descendants($brokerPid);
        $driverPids = array_column($driverTree, 'pid');
        $this->assertActualAmpWorker($driverTree);
        $this->assertNotSame([], $driverPids, 'Amp SQLite helper must exist under the broker after ready');
        $durableDb = $this->configuredMessengerTransportDbPath();
        $this->assertFileExists($durableDb);

        // Capture the owned tree before hard death so orphaned consumers can be
        // reaped by PID ownership after the controller is gone.
        $this->refreshTrackedControllerPids();
        $ownedBeforeKill = OwnedProcessTree::descendants($controllerPid);

        // Literal hard death of the owned QA controller only. Broker has its own
        // PGID, so killing the controller PID must not directly reap it.
        $this->assertTrue(@posix_kill($controllerPid, \SIGKILL));

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && $this->isControllerPidAlive($controllerPid)) {
            usleep(20_000);
        }
        $this->assertFalse($this->isControllerPidAlive($controllerPid), 'controller must exit after SIGKILL');

        while (microtime(true) < $deadline && $this->isControllerPidAlive($brokerPid)) {
            usleep(20_000);
        }
        $this->assertFalse($this->isControllerPidAlive($brokerPid), 'broker must exit after controller hard death closes owner stdin');
        foreach ($driverTree as $driver) {
            $this->assertFalse(OwnedProcessTree::stillAlive($driver), 'Actual owned Amp tree must exit before cleanup: '.json_encode($driver));
        }
        $this->assertFileExists($durableDb, 'durable queue DB must survive controller hard death');

        // Replay controllers intentionally omit HATFIELD_SESSION_ID from process
        // environ. Reap remaining owned same-UID leftovers by prior PID tracking.
        $this->reapOwnedLeftovers($ownedBeforeKill, [$controllerPid, $brokerPid, ...$driverPids]);

        // Detach dead first controller handles without group-killing foreign leftovers.
        $this->abandonKilledControllerHandles();

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $replacementPid = $this->controllerRootPid();
        $this->assertGreaterThan(0, $replacementPid);
        $replacementBroker = $this->waitForOwnedBroker($replacementPid, 5.0);
        $this->assertNotNull($replacementBroker, 'replacement controller must start a supervised broker against the durable DB');
        $this->assertSame(
            $durableDb,
            $this->configuredMessengerTransportDbPath(),
            'replacement must reuse the durable session queue database path',
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function brokerDeathModes(): iterable
    {
        yield 'live output' => [false];
        yield 'attachment waiting for mount acknowledgement' => [true];
    }

    #[DataProvider('brokerDeathModes')]
    public function testBrokerDeathAfterReadyEmitsProtocolErrorAndStopsOwnedTree(bool $pendingMount): void
    {
        if ($pendingMount) {
            $seed = new Process([\PHP_BINARY, __DIR__.'/E2E/Support/BootstrapSessionSeed.php', $this->projectDir], $this->tempDir,
                array_merge($this->controllerSubprocessEnv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir,
                    'HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'.sqlite',
                ]));
            $seed->setTimeout(5);
            $seed->mustRun();
            $this->runId = json_decode(trim($seed->getOutput()), true, 32, \JSON_THROW_ON_ERROR)['run_id'];
        }
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        if ($pendingMount) {
            $this->writeCommand(['v' => 1, 'id' => 'fatal-attachment', 'type' => 'resume', 'runId' => $this->runId]);
            $bootstrap = $this->indexByType($this->collectEventsUntil('bootstrap.end', 5));
            $this->assertArrayHasKey('bootstrap.available', $bootstrap);
            $this->assertArrayHasKey('bootstrap.end', $bootstrap);
            $this->assertArrayNotHasKey('session.ready', $bootstrap, 'Mount acknowledgement is deliberately withheld.');
        }

        $controllerPid = $this->controllerRootPid();
        $this->assertGreaterThan(0, $controllerPid);
        $this->assertTrue($this->isSameUidProcess($controllerPid));
        $this->assertFalse(
            $this->processEnvironContains($controllerPid, 'HATFIELD_SESSION_ID='),
            'harness controller must stay untagged for teardown safety',
        );

        $broker = $this->waitForOwnedBroker($controllerPid, 5.0);
        $this->assertNotNull($broker, 'supervised sqlite-queue:broker must exist after runtime.ready');
        $brokerPid = $broker['pid'];
        $this->assertTrue($this->isSameUidProcess($brokerPid));
        $this->assertFalse(
            $this->processEnvironContains($brokerPid, 'HATFIELD_SESSION_ID='),
            'owned broker must stay untagged; never signal HATFIELD_SESSION_ID processes',
        );

        $driverTree = OwnedProcessTree::descendants($brokerPid);
        $driverPids = array_column($driverTree, 'pid');
        $this->assertActualAmpWorker($driverTree);
        $this->assertNotSame([], $driverPids, 'Amp SQLite helper must exist under the broker after ready');
        $consumerPids = $this->findOwnedConsumers($controllerPid);
        $this->assertNotSame([], $consumerPids, 'messenger consumers must exist after runtime.ready');
        foreach ($consumerPids as $consumerPid) {
            $this->assertTrue($this->isSameUidProcess($consumerPid));
            $this->assertFalse($this->processEnvironContains($consumerPid, 'HATFIELD_SESSION_ID='));
        }

        $this->refreshTrackedControllerPids();

        // Kill only the owned same-UID untagged broker. Controller must observe
        // death via supervise() and fail closed with protocol.error.
        $this->assertTrue(@posix_kill($brokerPid, \SIGKILL));

        // Safety cap around SUPERVISE_INTERVAL (5s) plus shutdown; not synchronization.
        $terminalEvents = $this->indexByType($this->collectEventsUntil('protocol.error', 12.0));
        $this->assertArrayHasKey('protocol.error', $terminalEvents);
        $this->assertArrayNotHasKey('session.ready', $terminalEvents, 'Fatal shutdown must not complete an attachment.');
        $error = $terminalEvents['protocol.error'][0];
        $this->assertSame('protocol.error', $error['type'] ?? null);
        $this->assertSame('', $error['runId'] ?? null);
        $this->assertTrue($error['payload']['fatal'] ?? false, 'Broker death must explicitly fence process-client recovery.');
        $this->assertStringContainsString(
            'Session sqlite-queue broker exited after readiness',
            (string) ($error['payload']['error'] ?? ''),
        );

        $deadline = microtime(true) + 7.0;
        while (microtime(true) < $deadline && $this->isControllerPidAlive($controllerPid)) {
            usleep(20_000);
        }
        $this->assertFalse($this->isControllerPidAlive($controllerPid), 'controller must stop after broker death');
        $this->assertFalse($this->isControllerPidAlive($brokerPid), 'killed broker must stay dead');
        foreach ($driverTree as $driver) {
            $this->assertFalse(OwnedProcessTree::stillAlive($driver), 'Actual owned Amp tree must exit before cleanup: '.json_encode($driver));
        }
        foreach ($consumerPids as $consumerPid) {
            $this->assertFalse($this->isControllerPidAlive($consumerPid), 'owned consumers must exit with controller shutdown');
        }

        $this->abandonKilledControllerHandles();
    }

    public static function startupOutputModes(): iterable
    {
        yield 'normal output' => ['normal'];
        yield 'reader closes during terminal drain' => ['closed-terminal'];
        yield 'SIGTERM during blocked terminal drain' => ['sigterm-terminal'];
        yield 'SIGINT during blocked terminal drain' => ['sigint-terminal'];
    }

    #[DataProvider('startupOutputModes')]
    public function testStartupHookAndTerminalCleanup(string $terminalMode): void
    {
        $proof = $this->tempDir.'/startup-hook.json';
        $input = new InputStream();
        $process = new Process([\PHP_BINARY, __DIR__.'/E2E/Support/ControllerStartupHook.php', $this->projectDir, $proof, $terminalMode], $this->tempDir,
            array_merge($this->controllerSubprocessEnv(), [
                'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'HATFIELD_CWD' => $this->tempDir, 'HOME' => $this->tempDir,
                'HATFIELD_SESSION_ID' => false, 'HATFIELD_REPLAY_SESSION_ID' => $this->sessionId,
                'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'.sqlite',
                'HATFIELD_BINARY_PATH' => E2E\Replay\ControllerReplaySourceMessengerConsole::install($this->tempDir, $this->projectDir.'/bin/console'),
                ...\Ineersa\CodingAgent\Tests\Support\SessionSqliteQueueTestEnv::forSession($this->tempDir.'/.hatfield/sessions', $this->sessionId),
            ]), $input, 10);
        $tree = [];
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'runtime.ready')), $process->getErrorOutput());
            $tree = OwnedProcessTree::descendants($process->getPid());
            if ('normal' !== $terminalMode) {
                $process->wait();
            }
            $this->assertFileExists($proof, 'The owning shutdown must resume after terminal stdout fails.');
            $result = json_decode(file_get_contents($proof), true, 16, \JSON_THROW_ON_ERROR);
            $this->assertSame('startup-job', $result['received']);
            $this->assertSame([], $result['consumers'], 'Session-start hooks must run after broker readiness but before consumer launch.');
            $this->assertTrue($result['publisher_wrapped']);
            $this->assertTrue($result['receiver_unchanged']);
            if ('normal' !== $terminalMode) {
                $this->assertTrue($result['lifecycle']);
                $this->assertTrue($result['background_removed']);
                if (str_starts_with($terminalMode, 'sig')) {
                    $this->assertTrue($result['signal_observed']);
                    $this->assertTrue($result['terminal_written']);
                }
                $this->assertActualAmpWorker($result['tree']);
                foreach ($result['tree'] as $child) {
                    $this->assertFalse(OwnedProcessTree::stillAlive($child), 'Owned broker, Amp worker and consumers must exit before loop stop.');
                }
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Startup hook fixture failed: '.$process->getOutput().$process->getErrorOutput(), 0, $exception);
        } finally {
            $input->close();
            $process->wait();
        }
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        foreach ($tree as $child) {
            $this->assertFalse(OwnedProcessTree::stillAlive($child), 'Startup fixture owned tree must exit through stdin EOF.');
        }
    }

    protected function replayExtraEnv(): array
    {
        return ['HATFIELD_REPLAY_SESSION_ID' => '' !== $this->runId ? $this->runId : $this->sessionId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [];
    }

    protected function tempDirPrefix(): string
    {
        return 'controller-broker-hard-death';
    }

    protected function isolatedLlmWorkerCount(): int
    {
        return 1;
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
tools:
    execution:
        max_parallelism: 1
YAML;
    }

    /**
     * @return array{pid:int, cmd:string}|null
     */
    private function waitForOwnedBroker(int $controllerPid, float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $last = null;
        while (microtime(true) < $deadline) {
            if (!$this->isControllerPidAlive($controllerPid)) {
                return null;
            }
            $last = $this->findOwnedBroker($controllerPid);
            if (null !== $last) {
                return $last;
            }
            usleep(20_000);
        }

        return $last;
    }

    /**
     * @return list<int>
     */
    private function findOwnedConsumers(int $controllerPid): array
    {
        $pids = [];
        foreach ($this->discoverControllerProcessTreePids($controllerPid) as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!str_contains($cmd, 'messenger:consume')) {
                continue;
            }
            if (!$this->isSameUidProcess($pid) || !$this->isControllerPidAlive($pid)) {
                continue;
            }
            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * @return array{pid:int, cmd:string}|null
     */
    private function findOwnedBroker(int $controllerPid): ?array
    {
        foreach ($this->discoverControllerProcessTreePids($controllerPid) as $pid) {
            $cmdline = (string) @file_get_contents("/proc/{$pid}/cmdline");
            $cmd = str_replace("\0", ' ', $cmdline);
            if (!str_contains($cmd, 'sqlite-queue:broker')) {
                continue;
            }
            if (!$this->isSameUidProcess($pid) || !$this->isControllerPidAlive($pid)) {
                continue;
            }

            return ['pid' => $pid, 'cmd' => trim($cmd)];
        }

        return null;
    }

    private function processEnvironContains(int $pid, string $needle): bool
    {
        $environ = @file_get_contents("/proc/{$pid}/environ");
        if (false === $environ) {
            return false;
        }

        return str_contains($environ, $needle);
    }

    private function abandonKilledControllerHandles(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];
        if (\is_resource($this->process)) {
            @proc_close($this->process);
        }
        $this->process = null;
        $this->trackedControllerPids = [];
        $this->lastTeardownSurvivorPids = [];
        $this->stdoutBuf = '';
        $this->stderrBuf = '';
    }

    /**
     * @param list<array{pid:int, start:string, cmdline:string, exe:string}> $ownedPids
     * @param list<int>                                                      $alreadyDead
     */
    private function reapOwnedLeftovers(array $ownedPids, array $alreadyDead): void
    {
        $skip = array_fill_keys($alreadyDead, true);
        $alive = [];
        foreach ($ownedPids as $row) {
            $pid = $row['pid'];
            if ($pid <= 1 || isset($skip[$pid])) {
                continue;
            }
            if (OwnedProcessTree::canSignal($row)) {
                $alive[] = $row;
                @posix_kill($pid, \SIGTERM);
            }
        }

        $deadline = microtime(true) + 0.2;
        while (microtime(true) < $deadline) {
            $still = false;
            foreach ($alive as $row) {
                $pid = $row['pid'];
                if (OwnedProcessTree::canSignal($row)) {
                    $still = true;
                    break;
                }
            }
            if (!$still) {
                return;
            }
            usleep(10_000);
        }

        foreach ($alive as $row) {
            $pid = $row['pid'];
            if (OwnedProcessTree::canSignal($row)) {
                @posix_kill($pid, \SIGKILL);
            }
        }
    }

    /** @param list<array{pid:int, start:string, cmdline:string, exe:string}> $tree */
    private function assertActualAmpWorker(array $tree): void
    {
        $workers = array_filter($tree, static fn (array $row): bool => 'amp-process' === trim($row['cmdline']));
        $this->assertNotSame([], $workers, 'Actual Amp runner with rewritten process title must exist, not only its shell wrapper');
        foreach ($workers as $worker) {
            $this->assertSame(realpath(\PHP_BINARY), $worker['exe'], 'Actual source worker must execute the selected PHP interpreter');
        }
    }
}
