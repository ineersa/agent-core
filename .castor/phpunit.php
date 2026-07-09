<?php

declare(strict_types=1);

/**
 * PHPUnit / unit-test task definitions, configuration, and workers.
 *
 * Contains the `test` command (ParaTest-powered parallel by default,
 * sequential fallback for --filter and when ParaTest is unavailable.
 *
 * =========================================================================
 * MAINT-05B: Replaced custom file-shard fan-out with ParaTest as the
 * default `castor test` path.  The old `coding_agent_shard_groups`,
 * `build_test_worker_command`, and `build_test_variants_commands`
 * helpers have been removed.  Sequential PHPUnit is kept as an
 * internal fallback for --filter and when ParaTest is unavailable.
 * =========================================================================
 * MAINT-05E: Removed old live-LLM TUI E2E shard builders; the TUI
 * E2E suite is now a single deterministic replay-backed journey.
 * =========================================================================
 */

use Castor\Attribute\AsTask;

use function CastorTasks\check_lane_paratest_processes;
use function CastorTasks\is_llm_mode;
use function CastorTasks\phar_ensure;
use function CastorTasks\qa_test_home_shell_prefix;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/env.php';

// ─── Sequential baseline (deterministic, default) ────────────────

/**
 * Build the sequential PHPUnit command for the unit/integration
 * test suite (all suites, excluding E2E and real-LLM groups).
 *
 * This is used by both `castor test` (deterministic baseline) and
 * the unit/integration lane inside `castor check`.
 */
function build_sequential_phpunit_command(string $pharEnv): string
{
    $phpBin = \PHP_BINARY;
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';
    $junitFlag = is_llm_mode() ? ' --log-junit='.report_path('phpunit-sequential.junit.xml') : '';

    return qa_observability_env_command().' APP_ENV=test '.$pharEnv.$phpBin.' vendor/bin/phpunit'
        .' --exclude-group tui-e2e-replay --exclude-group llm-real --exclude-group recording --exclude-group controller-replay'
        .' '.$strictFlags.$llmFlags.$junitFlag;
}

// ─── Full test suite task (ParaTest parallel by default) ─────────

/**
 * Run the full unit/integration test suite — ParaTest PARALLEL by default.
 *
 * Defaults to ParaTest for fast multi-core execution (excludes TUI E2E,
 * live LLM, and controller/messenger E2E groups).  Falls back to
 * deterministic sequential PHPUnit when ParaTest is unavailable or when
 * --filter is used (ParaTest --filter can be unreliable with path/namespace
 * overlap).
 *
 * Each ParaTest worker gets its own compiled Symfony cache directory
 * (via TEST_TOKEN in tests/paratest-bootstrap.php).  The SQLite test DB
 * is shared — DAMA/DoctrineTestBundle provides per-test transaction
 * isolation in WAL mode.
 *
 * MAINT-05B: ParaTest is now the default.  Sequential PHPUnit is an
 * internal fallback only.
 *
 * MAINT-05F: Added --suite option to target specific test suites.
 */
#[AsTask(name: 'test', description: 'Run unit/integration tests (ParaTest parallel by default)')]
function test(?string $filter = null, ?string $suite = null): void
{
    // Prevent Xdebug overhead from hitting unit tests.
    if (extension_loaded('xdebug')) {
        echo "Xdebug is loaded — tests may be significantly slower.\n";
    }

    // Test DB schema readiness.
    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    $pharPath = '';
    try {
        $pharPath = phar_ensure();
    } catch (Throwable $e) {
        echo "PHAR ensure skipped: {$e->getMessage()}\n";
    }
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $start = hrtime(true);

    // Build suite flag for either PHPUnit or ParaTest.
    $suiteFlag = '';
    if (null !== $suite) {
        $suiteFlag = ' --testsuite='.escapeshellarg($suite);
    }

    if (null !== $filter) {
        // Filtered runs use single PHPUnit (ParaTest --filter can be unreliable).
        // Exclude groups that require live LLM or tmux (same as build_sequential_phpunit_command).
        $phpunitCmd = qa_observability_env_command().' APP_ENV=test '.$pharEnv.\PHP_BINARY.' vendor/bin/phpunit'
            .$suiteFlag
            .' --filter='.escapeshellarg($filter)
            .' --exclude-group=tui-e2e-replay --exclude-group=llm-real --exclude-group=recording --exclude-group=controller-replay'
            .' '.phpunit_strict_issue_flags();
        passthru($phpunitCmd, $exitCode);
        $duration = (hrtime(true) - $start) / 1e9;
        if (0 !== $exitCode) {
            echo sprintf("\nTests FAILED (%.1fs)\n", $duration);
            exit($exitCode);
        }
        echo sprintf("\nTests OK (%.1fs)\n", $duration);
        exit(0);
    }

    // Default: ParaTest parallel acceleration for unit/integration suites.
    // Falls back to sequential PHPUnit when ParaTest is not installed.
    if (!class_exists(ParaTest\ParaTestCommand::class)) {
        echo "ParaTest not installed — falling back to sequential PHPUnit.\n";
        $cmd = build_sequential_phpunit_command($pharEnv);
        passthru($cmd, $exitCode);
        $duration = (hrtime(true) - $start) / 1e9;
        if (0 !== $exitCode) {
            echo sprintf("\nTests FAILED (%.1fs)\n", $duration);
            exit($exitCode);
        }
        echo sprintf("\nTests OK (%.1fs)\n", $duration);
        exit(0);
    }

    $bootstrap = paratest_bootstrap_path();
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';
    $junitFlag = is_llm_mode() ? ' --log-junit='.report_path('phpunit-parallel.junit.xml') : '';

    $cmd = qa_observability_env_command().' APP_ENV=test '.$pharEnv.\PHP_BINARY.' vendor/bin/paratest'
        .' --configuration=phpunit.xml.dist'
        .' --bootstrap='.escapeshellarg($bootstrap)
        .$suiteFlag
        .' --exclude-group=tui-e2e-replay --exclude-group=llm-real --exclude-group=recording --exclude-group=controller-replay'
        .' '.$strictFlags.$llmFlags.$junitFlag;

    passthru($cmd, $exitCode);

    $duration = (hrtime(true) - $start) / 1e9;
    if (0 !== $exitCode) {
        echo sprintf("\nTests FAILED (%.1fs)\n", $duration);
        exit($exitCode);
    }
    echo sprintf("\nTests OK (%.1fs)\n", $duration);
    exit(0);
}

// ─── Check lane ParaTest command builder ───────────────────────

/**
 * Build the ParaTest command for the unit/integration lane in castor check.
 *
 * This is a standalone command builder — it does NOT call phar_ensure()
 * and does NOT wrap in passthru/exit.  It excludes ALL groups that require
 * live LLM, tmux, or external tooling: tui-e2e-replay, llm-real, recording,
 * controller-replay, and phar.  The gate is fully deterministic.
 */
function build_check_paratest_command(): string
{
    $phpBin = \PHP_BINARY;
    $bootstrap = paratest_bootstrap_path();
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';
    $junitFlag = is_llm_mode() ? ' --log-junit='.report_path('phpunit-parallel.junit.xml') : '';

    $processes = check_lane_paratest_processes('unit', 4, 8);

    return qa_check_run_env_command().' APP_ENV=test '.$phpBin.' vendor/bin/paratest'
        .' --processes='.$processes
        .' --configuration=phpunit.xml.dist'
        .' --bootstrap='.escapeshellarg($bootstrap)
        .' --exclude-group=tui-e2e-replay --exclude-group=llm-real --exclude-group=recording --exclude-group=controller-replay --exclude-group=phar'
        .' '.$strictFlags.$llmFlags.$junitFlag;
}

// ─── ParaTest internal helpers ────────────────────────────────

/**
 * ParaTest per-worker bootstrap path relative to the project root.
 */
function paratest_bootstrap_path(): string
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    return $root.'/tests/paratest-bootstrap.php';
}
