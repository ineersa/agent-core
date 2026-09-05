<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ownership predicates for TmuxHarness teardown must obey root AGENTS.md:
 * never signal root-owned or HATFIELD_SESSION_ID processes, and never use
 * blanket negative process-group signals over unexamined members.
 *
 * Children exit by closing stdin (no signals), so this file itself never
 * violates the session-tag rule during cleanup.
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
    public function sourceDoesNotEmitNegativeProcessGroupSignals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/TmuxHarness.php');
        $this->assertDoesNotMatchRegularExpression(
            '/posix_kill\(\s*-\s*\$/',
            $source,
            'TmuxHarness must not signal negative PGIDs; group members are not fully examined for HATFIELD_SESSION_ID.',
        );
    }

    #[Test]
    public function sessionTaggedSameUidChildIsNotSignable(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Root AGENTS.md forbids signaling from UID 0; predicate proof runs as non-root.');
        }

        $pid = $this->spawnChildWithEnv(['HATFIELD_SESSION_ID' => 'tmux-harness-safety-tagged']);
        $harness = new TmuxHarness();

        $this->assertTrue($this->invokePrivate($harness, 'isSameUidProcess', [$pid]));
        $this->assertTrue($this->invokePrivate($harness, 'hasHatfieldSessionId', [$pid]));
        $this->assertFalse($this->invokePrivate($harness, 'isSignableOwnedProcess', [$pid]));
    }

    #[Test]
    public function untaggedSameUidChildIsSignable(): void
    {
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Root AGENTS.md forbids signaling from UID 0; predicate proof runs as non-root.');
        }

        $pid = $this->spawnChildWithEnv([]);
        $harness = new TmuxHarness();

        $this->assertTrue($this->invokePrivate($harness, 'isSameUidProcess', [$pid]));
        $this->assertFalse($this->invokePrivate($harness, 'hasHatfieldSessionId', [$pid]));
        $this->assertTrue($this->invokePrivate($harness, 'isSignableOwnedProcess', [$pid]));
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function spawnChildWithEnv(array $extraEnv): int
    {
        $this->tempDir ??= TestDirectoryIsolation::createOsTempDir('tmux-harness-safety-');
        $pidFile = $this->tempDir.'/child.pid';
        @unlink($pidFile);

        $env = [];
        foreach ($_ENV as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $env[$key] = $value;
            }
        }
        // Ensure the session tag is either present exactly as requested or absent.
        unset($env['HATFIELD_SESSION_ID']);
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
