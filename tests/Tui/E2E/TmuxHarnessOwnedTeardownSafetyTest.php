<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TmuxHarness teardown must obey root AGENTS.md: never signal root-owned or
 * HATFIELD_SESSION_ID processes, never force-kill leftovers, and never destroy
 * a tmux session while protected descendants remain (kill-session would SIGHUP
 * them). Unreadable/empty environ is fail-closed as protected.
 *
 * Children used here exit by closing stdin (no signals), so this file itself
 * never violates the session-tag rule during cleanup.
 */
final class TmuxHarnessOwnedTeardownSafetyTest extends TestCase
{
    /** @var list<array{process: resource, stdin: resource}> */
    private array $children = [];

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if (\is_resource($child['stdin'])) {
                fclose($child['stdin']);
            }
            $deadline = microtime(true) + 1.0;
            do {
                $status = @proc_get_status($child['process']);
                $running = \is_array($status) && ($status['running'] ?? false);
                if (!$running) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            @proc_close($child['process']);
        }
        $this->children = [];

        if (null !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    #[Test]
    public function sessionTaggedSameUidChildIsProtected(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Root AGENTS.md forbids signaling from UID 0; predicate proof runs as non-root.');
        }

        $pid = $this->spawnChildWithEnv(['HATFIELD_SESSION_ID' => 'tmux-harness-safety-tagged']);
        $harness = new TmuxHarness();

        $this->assertTrue($this->invokePrivate($harness, 'isSameUidProcess', [$pid]));
        $this->assertTrue($this->invokePrivate($harness, 'isProtectedProcess', [$pid]));
        $this->assertFalse($this->invokePrivate($harness, 'isUntaggedOwnedProcess', [$pid]));
        $this->assertSame('HATFIELD_SESSION_ID', $this->invokePrivate($harness, 'protectedProcessReason', [$pid]));
    }

    #[Test]
    public function untaggedSameUidChildIsNotProtected(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Root AGENTS.md forbids signaling from UID 0; predicate proof runs as non-root.');
        }

        $pid = $this->spawnChildWithEnv([]);
        $harness = new TmuxHarness();

        $this->assertTrue($this->invokePrivate($harness, 'isSameUidProcess', [$pid]));
        $this->assertFalse($this->invokePrivate($harness, 'isProtectedProcess', [$pid]));
        $this->assertTrue($this->invokePrivate($harness, 'isUntaggedOwnedProcess', [$pid]));
    }

    #[Test]
    public function emptyEnvironIsProtectedFailClosed(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Root AGENTS.md forbids signaling from UID 0; predicate proof runs as non-root.');
        }

        // proc_open with an empty env yields an empty /proc/<pid>/environ on Linux.
        $pid = $this->spawnChildWithEnv([], clearInheritedEnv: true);
        $harness = new TmuxHarness();

        $this->assertTrue($this->invokePrivate($harness, 'isSameUidProcess', [$pid]));
        $this->assertTrue($this->invokePrivate($harness, 'isProtectedProcess', [$pid]));
        $this->assertFalse($this->invokePrivate($harness, 'isUntaggedOwnedProcess', [$pid]));
        $this->assertSame('environ-empty', $this->invokePrivate($harness, 'protectedProcessReason', [$pid]));
    }

    #[Test]
    #[Group('tui-e2e-replay')]
    public function killAllCleansSessionAfterProductShutdownWithoutForceSignals(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is required for owned teardown proof');
        }

        $harness = new TmuxHarness();
        $pane = $harness->startDetached(
            'exec php -r '.escapeshellarg('fwrite(STDOUT, "ready\\n"); fflush(STDOUT); fread(STDIN, 1);'),
            'tmux-harness-graceful',
            80,
            24,
        );
        $harness->waitForCaptureContains($pane, 'ready', 2.0);
        $panePid = $harness->panePid($pane);
        $this->assertGreaterThan(1, $panePid);

        $harness->sendKey($pane, 'C-d');
        $harness->killAll();
        $this->assertFalse($harness->paneExists($pane));
        $this->assertFalse(@posix_kill($panePid, 0), 'product shutdown plus harness wait must clear the pane process without force signals');
    }

    #[Test]
    public function finalizeOwnedSessionShutdownRefusesProtectedAliveTreeWithoutDestroy(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Root AGENTS.md forbids signaling from UID 0; predicate proof runs as non-root.');
        }

        $pid = $this->spawnChildWithEnv(['HATFIELD_SESSION_ID' => 'tmux-harness-protected-leak']);
        $harness = new TmuxHarness();
        $this->assertTrue($this->invokePrivate($harness, 'isProtectedProcess', [$pid]));

        try {
            $this->invokePrivate($harness, 'finalizeOwnedSessionShutdown', ['tmux-harness-protected-sim', $pid]);
            $this->fail('finalizeOwnedSessionShutdown must refuse destroy while a protected process remains');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing to destroy tmux session', $e->getMessage());
            $this->assertStringContainsString('HATFIELD_SESSION_ID', $e->getMessage());
            $this->assertTrue(@posix_kill($pid, 0), 'protected process must remain untouched');
        }
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function spawnChildWithEnv(array $extraEnv, bool $clearInheritedEnv = false): int
    {
        $this->tempDir ??= TestDirectoryIsolation::createOsTempDir('tmux-harness-safety-');
        $pidFile = $this->tempDir.'/child-'.bin2hex(random_bytes(4)).'.pid';
        @unlink($pidFile);

        $env = [];
        if (!$clearInheritedEnv) {
            foreach ($_ENV as $key => $value) {
                if (\is_string($key) && \is_string($value)) {
                    $env[$key] = $value;
                }
            }
            // Ensure the session tag is either present exactly as requested or absent.
            unset($env['HATFIELD_SESSION_ID']);
            // Guarantee a readable non-empty environ even when PHP $_ENV is empty.
            $env['TMUX_HARNESS_SAFETY'] = 'untagged';
            $env['PATH'] = getenv('PATH') ?: '/usr/bin:/bin';
        }
        foreach ($extraEnv as $key => $value) {
            $env[$key] = $value;
        }

        $script = \sprintf(
            'file_put_contents(%s, (string) getmypid()); fread(STDIN, 1);',
            var_export($pidFile, true),
        );

        $pipes = [];
        $process = proc_open(
            ['php', '-r', $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env,
        );
        $this->assertIsResource($process);
        $this->assertIsResource($pipes[0]);
        $this->children[] = ['process' => $process, 'stdin' => $pipes[0]];
        fclose($pipes[1]);
        fclose($pipes[2]);

        $deadline = microtime(true) + 2.0;
        $reported = '';
        while (microtime(true) < $deadline) {
            if (is_file($pidFile)) {
                $reported = trim((string) file_get_contents($pidFile));
                if ('' !== $reported && ctype_digit($reported)) {
                    break;
                }
            }
            $status = proc_get_status($process);
            if (\is_array($status) && !($status['running'] ?? true)) {
                break;
            }
            usleep(10_000);
        }

        $this->assertTrue(ctype_digit($reported), 'child did not publish pid file');
        $pid = (int) $reported;
        $this->assertGreaterThan(1, $pid);
        $this->assertTrue(@posix_kill($pid, 0), 'child pid must still be alive for predicate inspection');

        return $pid;
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);

        return $ref->invokeArgs($object, $args);
    }
}
