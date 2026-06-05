<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Phar;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
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
}
