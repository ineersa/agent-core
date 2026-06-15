<?php

declare(strict_types=1);

/**
 * PHPUnit / unit-test task definitions, configuration, and workers.
 *
 * Contains the `test` command (ParaTest-powered parallel by default,
 * sequential fallback for --filter and when ParaTest is unavailable)
 * and TUI E2E worker helpers (the TUI E2E shard/worker logic remains
 * for now; MAINT-05E will refactor it into journey-based tests).
 *
 * =========================================================================
 * MAINT-05B: Replaced custom file-shard fan-out with ParaTest as the
 * default `castor test` path.  The old `coding_agent_shard_groups`,
 * `build_test_worker_command`, and `build_test_variants_commands`
 * helpers have been removed.  Sequential PHPUnit is kept as an
 * internal fallback for --filter and when ParaTest is unavailable.
 * =========================================================================
 * MAINT-05E (future): TUI E2E shard/worker helpers will move into a
 * journey-based TUI harness and the remaining custom shard builders
 * will be removed.
 * =========================================================================
 */

use Castor\Attribute\AsTask;

use function CastorTasks\is_llm_mode;
use function CastorTasks\phar_ensure;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

// ─── TUI E2E shard helpers (retained for check() TUI steps) ─────
// These will be refactored or removed in MAINT-05E when TUI tests
// become journey-based.

/**
 * Return TUI E2E test files split across two shards for parallel
 * execution under the 75s per-step timeout.
 *
 * Only files ending in Test.php are included; harness/support files
 * (TmuxHarness.php, TmuxPane.php) are excluded so PHPUnit does not
 * attempt to load them as test classes.
 *
 * Known files (Jun 2026) balance:
 *   shard 1: TuiAgentSmokeTest (heaviest), EditorBorderColorTest,
 *            HotkeySmokeTest, ImmediateSubmitFeedbackTest
 *   shard 2: PromptTemplateSlashCommandE2ETest, ReasoningCycleTest,
 *            SessionRenameE2ETest, ShellPrefixSmokeTest,
 *            TuiStartupSnapshotTest
 *
 * New Test.php files are round-robined starting on the lighter shard.
 *
 * @return array<string, list<string>> file paths grouping
 */
function tui_e2e_shard_groups(): array
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $tuiE2eDir = $root.'/tests/Tui/E2E';
    if (!is_dir($tuiE2eDir)) {
        return ['tui-e2e-1' => [], 'tui-e2e-2' => []];
    }
    $allFiles = glob($tuiE2eDir.'/*.php');
    if (false === $allFiles) {
        $allFiles = [];
    }
    // Only include PHPUnit test files; skip harness/support files.
    $files = array_values(array_filter($allFiles, static fn (string $f): bool => str_ends_with(basename($f), 'Test.php')));
    sort($files, \SORT_STRING);
    $shard1 = [];
    $shard2 = [];
    foreach ($files as $file) {
        $basename = basename($file);
        if (in_array($basename, [
            'TuiAgentSmokeTest.php',
            'EditorBorderColorTest.php',
            'HotkeySmokeTest.php',
            'ImmediateSubmitFeedbackTest.php',
        ], true)) {
            $shard1[] = $file;
        } elseif (in_array($basename, [
            'PromptTemplateSlashCommandE2ETest.php',
            'ReasoningCycleTest.php',
            'SessionRenameE2ETest.php',
            'ShellPrefixSmokeTest.php',
            'TuiStartupSnapshotTest.php',
        ], true)) {
            $shard2[] = $file;
        } else {
            // New/unknown E2E Test.php file — round-robin to the
            // currently lighter shard.
            if (count($shard1) <= count($shard2)) {
                $shard1[] = $file;
            } else {
                $shard2[] = $file;
            }
        }
    }

    return ['tui-e2e-1' => $shard1, 'tui-e2e-2' => $shard2];
}

/**
 * Build a TUI E2E worker command for the given shard number.
 */
function build_tui_e2e_worker_command(int $shardNum, string $pharEnv): string
{
    $worker = 'tui-e2e-'.$shardNum;
    $dbEnv = 'HATFIELD_TEST_DATABASE_PATH='.escapeshellarg('app_test-tui-e2e-'.$shardNum.'.sqlite');
    $phpBin = \PHP_BINARY;
    $cacheDirEnv = 'HATFIELD_CACHE_DIR=.hatfield/cache-'.$worker;
    $cacheDir = 'var/cache/.phpunit-'.$worker;
    $junitFlag = is_llm_mode() ? ' --log-junit='.report_path('phpunit-'.$worker.'.junit.xml') : '';
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';

    $dirs = tui_e2e_shard_groups()[$worker] ?? [];
    if ([] === $dirs) {
        throw new RuntimeException("Unknown TUI E2E shard: {$worker}");
    }
    $phpunitArgs = implode(' ', array_map('escapeshellarg', $dirs));

    return 'APP_ENV=test '.$cacheDirEnv.' '.$dbEnv.' '.$phpBin.' bin/console'
        .' doctrine:migrations:migrate --no-interaction --allow-no-migration'
        .' && APP_ENV=test '.$cacheDirEnv.' '.$dbEnv.' '.$pharEnv.$phpBin.' vendor/bin/phpunit'
        .' '.$phpunitArgs
        .' --cache-directory '.escapeshellarg($cacheDir)
        .' '.$strictFlags.$llmFlags.$junitFlag;
}

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

    return 'APP_ENV=test '.$pharEnv.$phpBin.' vendor/bin/phpunit'
        .' --exclude-group tui-e2e --exclude-group llm-real --exclude-group recording'
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
 */
#[AsTask(name: 'test', description: 'Run unit/integration tests (ParaTest parallel by default)')]
function test(?string $filter = null): void
{
    // Prevent Xdebug overhead from hitting unit tests.
    if (extension_loaded('xdebug')) {
        echo "Xdebug is loaded — tests may be significantly slower.\n";
    }

    // Test DB schema readiness.
    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
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

    if (null !== $filter) {
        // Filtered runs use a single sequential PHPUnit invocation —
        // ParaTest --filter can be unreliable with path/namespace overlap.
        passthru('APP_ENV=test '.$pharEnv.\PHP_BINARY.' vendor/bin/phpunit --filter='.escapeshellarg($filter).' '.phpunit_strict_issue_flags(), $exitCode);
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

    $cmd = 'APP_ENV=test '.$pharEnv.\PHP_BINARY.' vendor/bin/paratest'
        .' --configuration=phpunit.xml.dist'
        .' --bootstrap='.escapeshellarg($bootstrap)
        .' --exclude-group=tui-e2e --exclude-group=llm-real --exclude-group=recording'
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

// ─── ParaTest internal helpers ────────────────────────────────

/**
 * ParaTest per-worker bootstrap path relative to the project root.
 */
function paratest_bootstrap_path(): string
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    return $root.'/tests/paratest-bootstrap.php';
}
