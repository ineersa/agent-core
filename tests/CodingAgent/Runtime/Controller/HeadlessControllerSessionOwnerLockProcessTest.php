<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Thesis: only one live controller may own a given project CWD + session ID.
 * A second controller for the same pair must fail without emitting runtime.ready,
 * preventing two consumer pools from sharing session broker queues.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerSessionOwnerLockProcessTest extends ControllerReplayE2eTestCase
{
    /** @var resource|null */
    private mixed $secondProcess = null;

    /** @var array<int, resource> */
    private array $secondPipes = [];

    /** @var list<int> Independent ownership list for the second controller tree. */
    private array $secondTrackedPids = [];

    private string $secondStdoutBuf = '';
    private string $secondStderrBuf = '';

    protected function tearDown(): void
    {
        $this->stopSecondProcess();
        $this->stopProcess();
        $this->assertNoTrackedControllerProcessSurvivors();
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        $this->tempDir = '';
        \PHPUnit\Framework\TestCase::tearDown();
    }

    public function testSecondControllerForSameSessionIsRejectedWithoutRuntimeReady(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $this->spawnSecondControllerSameSession();
        $this->assertSecondControllerRejectedWithoutRuntimeReady();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function foreignBrokerIdentities(): iterable
    {
        yield 'session 1 must not select session 10 in the same project' => ['10', false];
        yield 'session 1 must not select session 1 in another project' => ['1', true];
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function consumerIdentities(): iterable
    {
        yield 'exact session and project' => ["PATH=/bin\0HATFIELD_SESSION_ID=1\0OTHER=value\0", false, true];
        yield 'first environment entry' => ["HATFIELD_SESSION_ID=1\0PATH=/bin\0", false, true];
        yield 'session 10 is foreign' => ["HATFIELD_SESSION_ID=10\0", false, false];
        yield 'session 11 is foreign' => ["HATFIELD_SESSION_ID=11\0", false, false];
        yield 'session 100 is foreign' => ["HATFIELD_SESSION_ID=100\0", false, false];
        yield 'different environment key' => ["OTHER_HATFIELD_SESSION_ID=1\0", false, false];
        yield 'same session in foreign project' => ["HATFIELD_SESSION_ID=1\0", true, false];
        yield 'missing session identity' => ["PATH=/bin\0", false, false];
    }

    #[DataProvider('consumerIdentities')]
    public function testConsumerIdentityRequiresExactEnvironmentEntryAndProject(string $environment, bool $differentProject, bool $expected): void
    {
        $cwd = $differentProject ? TestDirectoryIsolation::createProjectTempDir('foreign-consumer') : $this->tempDir;
        try {
            // Exercise the production identity predicate without signaling tagged processes.
            $identity = new \ReflectionMethod(HeadlessController::class, 'matchesConsumerIdentity');
            $this->assertSame($expected, $identity->invoke(null, $environment, $cwd, '1', $this->tempDir));
        } finally {
            if ($differentProject) {
                TestDirectoryIsolation::removeDirectory($cwd);
            }
        }
    }

    #[DataProvider('foreignBrokerIdentities')]
    public function testOrphanConsumerSelectorsExcludeForeignBrokers(string $brokerSessionId, bool $differentProject): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('The orphan consumer scanner uses Linux /proc.');
        }

        $cwd = $differentProject ? TestDirectoryIsolation::createProjectTempDir('foreign-broker') : $this->tempDir;
        [$php] = AgentTestExecutable::sourceConsoleCommand();
        $owner = new InputStream();
        // A broker-shaped fixture proves the actual pgrep selectors exclude it
        // before any PPID/environment checks, even if it later becomes orphaned.
        // Close stdin to release this session-tagged fixture; never signal it.
        $fixture = new Process(
            [$php, '-r', 'fwrite(STDOUT, "broker-fixture-ready\n"); while (false !== fgets(STDIN)) {}', 'sqlite-queue:broker', '--database='.$cwd.'/.hatfield/sessions/'.$brokerSessionId.'/queue.sqlite'],
            $cwd,
            ['HATFIELD_SESSION_ID' => $brokerSessionId],
            $owner,
            null,
        );

        try {
            $fixture->start();
            $clock = new MonotonicClock();
            $deadline = $clock->now()->modify('+5 seconds');
            $output = '';
            while ($fixture->isRunning() && $clock->now() < $deadline) {
                $output .= $fixture->getIncrementalOutput();
                if (str_contains($output, 'broker-fixture-ready')) {
                    break;
                }
                $clock->sleep(0.01);
            }
            $this->assertStringContainsString('broker-fixture-ready', $output);
            $pid = $fixture->getPid();
            $this->assertNotNull($pid);

            $visibleBroker = new Process(['pgrep', '-f', 'sqlite-queue:broker'], timeout: 5);
            $visibleBroker->mustRun();
            $this->assertContains((string) $pid, explode("\n", trim($visibleBroker->getOutput())), 'Positive control: pgrep can see the foreign broker fixture.');

            $patterns = new \ReflectionClass(HeadlessController::class)->getConstant('ORPHAN_CONSUMER_PATTERNS');
            $this->assertIsArray($patterns);
            $this->assertNotEmpty($patterns);
            foreach ($patterns as $pattern) {
                $this->assertIsString($pattern);
                $selection = new Process(['pgrep', '-f', $pattern], timeout: 5);
                $selection->run();
                $this->assertContains($selection->getExitCode(), [0, 1]);
                $this->assertNotContains((string) $pid, explode("\n", trim($selection->getOutput())));
            }
        } finally {
            $owner->close();
            if ($fixture->isStarted()) {
                $fixture->wait();
            }
            if ($differentProject) {
                TestDirectoryIsolation::removeDirectory($cwd);
            }
        }
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
        return 'controller-session-owner-lock';
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

    private function spawnSecondControllerSameSession(): void
    {
        [$php, $script] = AgentTestExecutable::sourceConsoleCommand();
        $this->assertFileExists($script, 'Agent executable not found at '.$script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '1',
            // Distinct DB files so lock conflict is not confounded with SQLite file locks.
            'HATFIELD_TEST_DATABASE_PATH' => 'app_test-replay-'.$this->sessionId.'-b.sqlite',
            'HATFIELD_SESSION_ID' => $this->sessionId,
            ...\Ineersa\CodingAgent\Tests\Support\SessionSqliteQueueTestEnv::forSession(
                $this->tempDir.'/.hatfield/sessions',
                $this->tempDir,
                $this->sessionId,
            ),
        ];

        $pipes = [];
        $process = @proc_open(
            array_merge(
                ['setsid', '-w', $php, $script, 'agent', '--controller', '--cwd='.$this->tempDir],
                $this->controllerExtraArgs(),
            ),
            $descriptors,
            $pipes,
            $this->tempDir,
            $env,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('Failed to spawn second controller process.');
        }

        $this->secondProcess = $process;
        $this->secondPipes = $pipes;
        stream_set_blocking($pipes[0], true);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        // Do NOT call trackControllerProcessTree() — that overwrites the first
        // controller's ownership list. Keep a separate PID list for teardown.
        $status = @proc_get_status($process);
        $rootPid = \is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
        $this->secondTrackedPids = $rootPid > 0
            ? array_values(array_unique(array_merge(
                [$rootPid],
                $this->discoverControllerProcessTreePids($rootPid),
            )))
            : [];
    }

    private function assertSecondControllerRejectedWithoutRuntimeReady(): void
    {
        $deadline = microtime(true) + $this->liveControllerReadyTimeout();
        $sawReady = false;

        while (microtime(true) < $deadline) {
            $this->drainSecondStdout();
            if (str_contains($this->secondStdoutBuf, '"type":"runtime.ready"')
                || str_contains($this->secondStdoutBuf, '"type": "runtime.ready"')) {
                $sawReady = true;
                break;
            }

            if (null !== $this->secondProcess) {
                $status = proc_get_status($this->secondProcess);
                if (!$status['running']) {
                    break;
                }
            }

            usleep(50_000);
        }

        $this->drainSecondStdout();
        $this->drainSecondStderr();

        $this->assertFalse(
            $sawReady,
            "Second controller must not emit runtime.ready while first owner is healthy.\nstdout="
            .$this->secondStdoutBuf."\nstderr=".$this->secondStderrBuf,
        );

        if (null !== $this->secondProcess) {
            $status = proc_get_status($this->secondProcess);
            if ($status['running']) {
                // Owner lock rejects before event loop; process should exit quickly.
                $this->stopSecondProcess();
                $this->fail(
                    "Second controller still running without runtime.ready.\nstdout="
                    .$this->secondStdoutBuf."\nstderr=".$this->secondStderrBuf,
                );
            }

            $this->assertNotSame(
                0,
                (int) ($status['exitcode'] ?? -1),
                "Second controller must exit non-zero when session owner lock is held.\nstdout="
                .$this->secondStdoutBuf."\nstderr=".$this->secondStderrBuf,
            );
        }
    }

    private function drainSecondStdout(): void
    {
        if (!isset($this->secondPipes[1]) || !\is_resource($this->secondPipes[1])) {
            return;
        }

        $chunk = stream_get_contents($this->secondPipes[1]);
        if (\is_string($chunk) && '' !== $chunk) {
            $this->secondStdoutBuf .= $chunk;
        }
    }

    private function drainSecondStderr(): void
    {
        if (!isset($this->secondPipes[2]) || !\is_resource($this->secondPipes[2])) {
            return;
        }

        $chunk = stream_get_contents($this->secondPipes[2]);
        if (\is_string($chunk) && '' !== $chunk) {
            $this->secondStderrBuf .= $chunk;
        }
    }

    private function stopSecondProcess(): void
    {
        foreach ($this->secondPipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->secondPipes = [];

        if (null === $this->secondProcess) {
            return;
        }

        if (\is_resource($this->secondProcess)) {
            $status = @proc_get_status($this->secondProcess);
            $rootPid = \is_array($status) && isset($status['pid']) ? (int) $status['pid'] : 0;
            if ($rootPid > 0) {
                $this->secondTrackedPids = array_values(array_unique(array_merge(
                    $this->secondTrackedPids,
                    [$rootPid],
                    $this->discoverControllerProcessTreePids($rootPid),
                )));
            }

            $this->terminateTrackedControllerPids($this->secondTrackedPids);
            @proc_close($this->secondProcess);
        }

        $this->secondProcess = null;
        $this->secondTrackedPids = [];
    }
}
