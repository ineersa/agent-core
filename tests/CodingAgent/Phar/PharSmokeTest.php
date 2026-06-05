<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Built PHAR smoke test.
 *
 * Validates that the PHAR exists (e.g. /tmp/bin/hatfield.phar when
 * HATFIELD_BINARY_PATH is set) and that it boots sufficiently to
 * respond to `php hatfield.phar list` with the expected agent command.
 *
 * This test is NOT in the llm-real group because it does not need
 * llama.cpp. It is not in any group by default — run it explicitly:
 *
 *   HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest
 *
 * Or through Castor:
 *
 *   castor phar:build && HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest
 */
#[Group('phar')]
final class PharSmokeTest extends TestCase
{
    /**
     * Default PHAR path used in skip messages.
     *
     * The actual path is resolved via HATFIELD_BINARY_PATH env var
     * (set by Castor tasks) or AgentTestExecutable.  This constant mirrors
     * the build default from .castor/helpers.php:hatfield_phar_path().
     */
    private const string DEFAULT_PHAR_PATH = '/tmp/bin/hatfield.phar';

    public function testPharBootingToAgentList(): void
    {
        [$php, $pharPath] = AgentTestExecutable::command();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            self::markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s. '
                .'Run: castor phar:build && HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest',
                $pharPath,
            ));
        }

        self::assertFileExists($pharPath, 'PHAR not found at '.$pharPath);
        self::assertFileIsReadable($pharPath);

        $output = shell_exec($php.' '.escapeshellarg($pharPath).' list 2>&1');
        self::assertNotNull($output, 'PHAR list command produced no output');
        self::assertStringContainsString('agent', $output, 'PHAR list output should contain the agent command');

        $sizeMb = filesize($pharPath) / 1024 / 1024;
        self::assertLessThan(
            20.0,
            $sizeMb,
            \sprintf('PHAR size %.1f MB exceeds 20 MB limit', $sizeMb),
        );

        echo \sprintf("\nPHAR smoke test ok: %s (%.1f MB)\n", $pharPath, $sizeMb);
    }

    public function testPharAgentHelp(): void
    {
        [$php, $pharPath] = AgentTestExecutable::command();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            self::markTestSkipped(\sprintf(
                'HATFIELD_BINARY_PATH not set or not a PHAR. Resolved to %s. '
                .'Run: castor phar:build && HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar vendor/bin/phpunit --filter PharSmokeTest',
                $pharPath,
            ));
        }

        // Also verify that --help works on the agent command
        $output = shell_exec($php.' '.escapeshellarg($pharPath).' agent --help 2>&1');
        self::assertNotNull($output, 'PHAR agent --help produced no output');
        self::assertStringContainsString('Usage:', $output);
    }

    /**
     * Verify the PHAR boots correctly from the repo root where a source-tree
     * vendor/ directory exists alongside the PHAR's bundled vendor.
     *
     * When APP_ENV=dev is inherited from Castor's .env loading, a stale
     * source-checkout dev cache (kernel.project_dir pointing to filesystem
     * paths) would be reused by the PHAR, causing Cannot-redeclare-class
     * collisions between the PHAR's autoloader and source-tree vendor files.
     *
     * Cache isolation (PHAR-specific hash suffix on cache dirs) prevents
     * this. This test explicitly runs the PHAR from the repo root to catch
     * regressions.
     */
    #[Group('phar')]
    public function testPharRunsFromRepoRootWithSourceTreeVendor(): void
    {
        [$php, $pharPath] = AgentTestExecutable::command();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            self::markTestSkipped('Not running as PHAR — requires HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar');
        }

        // Run from repo root (where source-tree vendor/ is visible).
        // If cache isolation is broken, this triggers autoloader collisions.
        // Force APP_ENV=prod — the PHAR is a production artifact without dev
        // bundles. Inheriting APP_ENV=test from PHPUnit would cause
        // Class-not-found errors for test-only bundles like DAMADoctrineTestBundle.
        $repoRoot = dirname(__DIR__, 3);
        $process = Process::fromShellCommandline(
            \sprintf('APP_ENV=prod %s %s list', escapeshellarg($php), escapeshellarg($pharPath)),
            cwd: $repoRoot,
        );
        $process->mustRun();

        $output = $process->getOutput();
        self::assertStringContainsString('agent', $output, 'PHAR list must contain agent command when run from repo root');
    }

    /**
     * Verify the PHAR controller reaches runtime.ready when spawned from
     * the repo root where source-tree vendor/ is visible alongside the
     * PHAR's bundled vendor.
     *
     * This is the exact scenario that caused the Cannot-redeclare-class
     * fatal in production: Castor run:agent inherited APP_ENV=dev from .env,
     * a stale source-checkout dev cache (with filesystem vendor paths
     * embedded) was reused by the PHAR, and the controller subprocess
     * crashed before emitting runtime.ready.
     *
     * The test primes a source-checkout dev cache in an isolated temp
     * directory, then runs the PHAR agent --controller from the repo root
     * (process cwd = repo root, exposing source vendor/) with the same
     * isolated cache dir. With proper PHAR cache isolation, this reaches
     * runtime.ready without collision.
     *
     * This test does NOT need llama.cpp — runtime.ready is emitted by the
     * controller event loop before any LLM interaction.
     */
    #[Group('phar')]
    public function testPharControllerReachesRuntimeReadyFromRepoRoot(): void
    {
        [$php, $pharPath] = AgentTestExecutable::command();
        $isPhar = str_ends_with($pharPath, '.phar');

        if (!$isPhar) {
            self::markTestSkipped('Not running as PHAR — requires HATFIELD_BINARY_PATH=/tmp/bin/hatfield.phar');
        }

        $repoRoot = ProjectDir::get();

        // --- Create isolated temp dir for cache, logs, and DB ---
        $tmpDir = sys_get_temp_dir().'/phar-ctrl-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir, 0777, true);

        $cacheDir = $tmpDir.'/cache';
        $logDir = $tmpDir.'/logs';
        $dbPath = $tmpDir.'/messenger.sqlite';

        try {
            // Create an empty SQLite DB so Doctrine can connect.
            $db = new \PDO('sqlite:'.$dbPath);
            unset($db);

            // --- Prime a stale dev cache using the source checkout ---
            // This writes container cache files that embed filesystem-
            // based project_dir and vendor paths. If the PHAR reuses
            // this cache without isolation (the pre-fix behavior), the
            // cached container's auto-prepend file loads the source-
            // checkout autoloader, colliding with the PHAR's bundled one.
            $sourceConsole = $repoRoot.'/bin/console';

            // Inherit parent env so PATH, HOME, etc. are present,
            // then override only the cache/log/CWD env vars.
            $primingEnv = array_merge(
                \is_array(getenv()) ? getenv() : [],
                [
                    'APP_ENV' => 'dev',
                    'APP_DEBUG' => '1',
                    'HATFIELD_CACHE_DIR' => $cacheDir,
                    'HATFIELD_LOG_DIR' => $logDir,
                    'HATFIELD_CWD' => $tmpDir,
                ],
            );

            $primeProcess = new Process(
                [$php, $sourceConsole, 'list'],
                cwd: $repoRoot,
                env: $primingEnv,
                timeout: 30.0,
            );
            $primeProcess->mustRun();

            // Verify the primed cache contains a compiled dev container.
            $primedContainerDir = glob($cacheDir.'/dev/Container*', GLOB_ONLYDIR);
            self::assertNotEmpty(
                $primedContainerDir,
                'Expected source-checkout dev cache to contain a compiled Container* directory after priming. '.
                'Contents of '.$cacheDir.'/dev/: '.(is_dir($cacheDir.'/dev') ? implode(', ', scandir($cacheDir.'/dev') ?: []) : 'missing'),
            );

            // --- Run PHAR agent --controller from repo root ---
            // The process cwd is repo root (source vendor/ is visible).
            // APP_ENV=dev + same HATFIELD_CACHE_DIR pointing at the primed
            // stale cache means the PHAR would reuse it if cache isolation
            // is broken, causing Cannot-redeclare-class fatals.
            $descriptors = [
                0 => ['pipe', 'r'],   // stdin
                1 => ['pipe', 'w'],   // stdout → JSONL events
                2 => ['pipe', 'w'],   // stderr → diagnostics/fatals
            ];

            // Inherit parent env so PATH, HOME, etc. are present,
            // then override with PHAR and transport-specific vars.
            $controllerEnv = array_merge(
                \is_array(getenv()) ? getenv() : [],
                [
                    'APP_ENV' => 'dev',
                    'APP_DEBUG' => '1',
                    'HATFIELD_CACHE_DIR' => $cacheDir,
                    'HATFIELD_LOG_DIR' => $logDir,
                    'HATFIELD_CWD' => $tmpDir,
                    'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => 'sync://',
                    'HATFIELD_LLM_TRANSPORT_DSN' => 'sync://',
                    'HATFIELD_TOOL_TRANSPORT_DSN' => 'sync://',
                ],
            );

            $pipes = [];
            $controller = proc_open(
                [$php, $pharPath, 'agent', '--controller', '--tools-excluded=bash'],
                $descriptors,
                $pipes,
                $repoRoot,  // cwd: repo root → source vendor/ is visible!
                $controllerEnv,
            );

            if (!\is_resource($controller)) {
                self::fail('Failed to spawn PHAR agent --controller via proc_open().');
            }

            // Close stdin immediately — we only need runtime.ready.
            @fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdoutBuf = '';
            $stderrBuf = '';
            $runtimeReady = false;
            $timeout = 15.0;
            $deadline = microtime(true) + $timeout;

            while (microtime(true) < $deadline) {
                // Drain stdout.
                $chunk = stream_get_contents($pipes[1]);
                if (false !== $chunk && '' !== $chunk) {
                    $stdoutBuf .= $chunk;

                    // Parse complete JSONL lines.
                    $lastNewline = strrpos($stdoutBuf, "\n");
                    if (false !== $lastNewline) {
                        $complete = substr($stdoutBuf, 0, $lastNewline + 1);
                        $stdoutBuf = substr($stdoutBuf, $lastNewline + 1);

                        foreach (explode("\n", trim($complete)) as $line) {
                            if ('' === $line) {
                                continue;
                            }

                            try {
                                $event = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                                if (\is_array($event) && 'runtime.ready' === ($event['type'] ?? '')) {
                                    $runtimeReady = true;
                                    break 2;
                                }
                            } catch (\JsonException) {
                                // Skip non-JSON stdout lines.
                            }
                        }
                    }
                }

                // Drain stderr.
                $stderrChunk = stream_get_contents($pipes[2]);
                if (false !== $stderrChunk && '' !== $stderrChunk) {
                    $stderrBuf .= $stderrChunk;
                }

                // Check if process already exited.
                $status = proc_get_status($controller);
                if (\is_array($status) && !$status['running']) {
                    // Drain any remaining output.
                    $stdoutBuf .= (string) stream_get_contents($pipes[1]);
                    $stderrBuf .= (string) stream_get_contents($pipes[2]);
                    break;
                }

                usleep(20_000);
            }

            // Terminate the controller before closing pipes, otherwise
            // proc_close hangs because the controller event loop is
            // still running (with spawned consumers).
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_terminate($controller, \SIGTERM);
            // Wait up to 3s for graceful shutdown.
            $termDeadline = microtime(true) + 3.0;
            while (microtime(true) < $termDeadline) {
                $status = @proc_get_status($controller);
                if (!\is_array($status) || !$status['running']) {
                    break;
                }
                usleep(50_000);
            }
            // Force-kill if still alive.
            @proc_terminate($controller, \SIGKILL);
            @proc_close($controller);

            // --- Assertions ---
            self::assertTrue(
                $runtimeReady,
                'PHAR agent --controller did not emit runtime.ready within '.$timeout.'s from repo root.

'.
                'Stdout (last 2048 chars): '.substr($stdoutBuf, -2048)."\n\n".
                'Stderr (last 4096 chars): '.substr($stderrBuf, -4096)."\n",
            );

            // Verify stderr contains no fatal class-redeclaration errors.
            self::assertStringNotContainsString(
                'Cannot redeclare class',
                $stderrBuf,
                'PHAR controller stderr must not contain class-redeclaration fatal errors. '.
                'This indicates autoloader collision between PHAR-bundled and source-tree vendor files.',
            );

            self::assertStringNotContainsString(
                'Fatal error',
                $stderrBuf,
                'PHAR controller stderr must not contain PHP fatal errors.',
            );
        } finally {
            // Clean up temp dir.
            if (is_dir($tmpDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        @rmdir($file->getPathname());
                    } else {
                        @unlink($file->getPathname());
                    }
                }
                @rmdir($tmpDir);
            }
        }
    }
}
