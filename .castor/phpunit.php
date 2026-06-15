<?php

declare(strict_types=1);

/**
 * PHPUnit / unit-test task definitions and configuration.
 *
 * Contains the `test` command, worker command builders, shard-group
 * discovery, and TU/E2E worker helpers.
 *
 * =========================================================================
 * FUTURE (MAINT-05B): ParaTest will replace the custom shard-group
 * discovery (`coding_agent_shard_groups`, `tui_e2e_shard_groups`)
 * and the `build_test_worker_command` / `build_tui_e2e_worker_command`
 * machinery.  Keep these functions intact for now so the existing
 * commands continue to work; ParaTest integration will bypass them.
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

// ─── Shard-group discovery ───────────────────────────────────────

/**
 * Discover all *Test.php files under tests/CodingAgent/ recursively
 * and distribute them across 4 file-level round-robin shards for
 * balanced parallel execution under the 75s per-step timeout.
 *
 * File-level round-robin avoids the imbalance of top-level directory
 * grouping (e.g. 29 files in Auth+Extension+Phar+Skills+Tool vs 6 in
 * EventListener+Path+Session+TestCase).  New Test.php files are
 * picked up automatically by RecursiveDirectoryIterator and
 * round-robined after the sorted list.
 *
 * Verified balances (Jun 2026): 28 / 27 / 27 / 27 files across
 * shards 1-4 with 109 total Test.php files.
 *
 * @return array<string, list<string>> file paths grouping
 */
function coding_agent_shard_groups(): array
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $testDir = $root.'/tests/CodingAgent';
    $files = [];

    if (is_dir($testDir)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files, \SORT_STRING);

    $numShards = 4;
    $shards = array_fill(1, $numShards, []);
    foreach ($files as $i => $f) {
        $shardIdx = ($i % $numShards) + 1;
        $shards[$shardIdx][] = $f;
    }

    $result = [];
    for ($i = 1; $i <= $numShards; ++$i) {
        $result['coding-agent-'.$i] = $shards[$i];
    }

    return $result;
}

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

// ─── Worker command builders ─────────────────────────────────────

/**
 * Build the PHPUnit invocation for one test worker.
 *
 * For phpunit.xml.dist suites (agent-core, tui, platform) uses
 * --testsuite.  For coding-agent-N shards uses directory paths so
 * only the shard's portion of the suite is executed.
 */
function build_test_worker_command(
    string $worker,
    string $pharEnv,
    string $dbFilename,
    ?string $reportWorker = null,
): string {
    $dbEnv = 'HATFIELD_TEST_DATABASE_PATH='.escapeshellarg($dbFilename);
    $phpBin = \PHP_BINARY;
    // Each shard needs its own Symfony cache dir because %env(...)%
    // resolution in container compilation bakes HATFIELD_TEST_DATABASE_PATH
    // into the compiled container.  Sharing .hatfield/cache/test/ across
    // parallel workers causes stale-container reuse with a foreign DB
    // path → SQLite "database is locked" / wrong-schema errors.
    $cacheDirEnv = 'HATFIELD_CACHE_DIR=.hatfield/cache-'.$worker;
    $cacheDir = 'var/cache/.phpunit-'.$worker;
    $junitFilename = 'phpunit-'.($reportWorker ?? $worker).'.junit.xml';
    $junitFlag = is_llm_mode() ? ' --log-junit='.report_path($junitFilename) : '';
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';

    $phpunitArgs = '';
    if (str_starts_with($worker, 'coding-agent-')) {
        // Shard: pass directory paths directly (not --testsuite).
        $dirs = coding_agent_shard_groups()[$worker] ?? [];
        if ([] === $dirs) {
            throw new RuntimeException("Unknown coding-agent shard: {$worker}");
        }
        $phpunitArgs = implode(' ', array_map('escapeshellarg', $dirs));
    } else {
        $phpunitArgs = '--testsuite '.escapeshellarg($worker);
    }

    return 'APP_ENV=test '.$cacheDirEnv.' '.$dbEnv.' '.$phpBin.' bin/console'
        .' doctrine:migrations:migrate --no-interaction --allow-no-migration'
        .' && APP_ENV=test '.$cacheDirEnv.' '.$dbEnv.' '.$pharEnv.$phpBin.' vendor/bin/phpunit'
        .' '.$phpunitArgs
        .' --exclude-group tui-e2e --exclude-group llm-real'
        .' --cache-directory '.escapeshellarg($cacheDir)
        .' '.$strictFlags.$llmFlags.$junitFlag;
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

/**
 * @return array<string, array{cmd: string}>
 */
function build_test_variants_commands(string $pharEnv): array
{
    $commands = [];
    foreach (array_merge(['agent-core'], array_keys(coding_agent_shard_groups()), ['tui', 'platform']) as $worker) {
        $step = match ($worker) {
            'tui' => 'test-tui-suite',
            default => 'test-'.$worker,
        };
        $commands[$step] = [
            'cmd' => timeout_check_command(
                build_test_worker_command($worker, $pharEnv, 'app_test-'.$worker.'.sqlite', $step),
                90,
            ),
        ];
    }

    return $commands;
}

// ─── Full test suite task ─────────────────────────────────────────

/**
 * Run the full unit/integration test suite.
 *
 * Runs all suites (agent-core, coding-agent-1..4, tui, platform) in
 * parallel via proc_open subprocesses.  Each worker gets an isolated
 * DB, cache dir, and JUnit log.
 *
 * =========================================================================
 * FUTURE (MAINT-05B): A `test:parallel` command will use ParaTest for
 * the PHPUnit-level parallelism.  This sequential baseline path remains
 * as `castor test` for deterministic local validation.
 * =========================================================================
 */
#[AsTask(name: 'test', description: 'Run full test suite (runs all suites in parallel via proc_open subprocesses)')]
function test(?string $filter = null): void
{
    // Prevent Xdebug overhead from hitting unit tests.
    if (extension_loaded('xdebug')) {
        echo 'Xdebug is loaded — tests may be significantly slower.
';
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
        echo "PHAR ensure skipped: {$e->getMessage()}
";
    }
    if ('' !== $pharPath) {
        $GLOBALS['CASTOR_PHAR_READY'] = $pharPath;
    }
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    if (null !== $filter) {
        // filtered runs use a single sequential PHPUnit invocation
        echo 'Running filtered PHPUnit tests...
';
        passthru('APP_ENV=test '.$pharEnv.\PHP_BINARY.' vendor/bin/phpunit --filter='.escapeshellarg($filter).' '.phpunit_strict_issue_flags(), $exitCode);
        exit($exitCode);
    }

    $useParallel = \PHP_SAPI === 'cli' && function_exists('proc_open');

    $failures = [];
    $timings = [];

    $GLOBALS['CASTOR_CHECK_AGGREGATING'] = true;
    try {
        if ($useParallel) {
            run_check_commands_parallel(build_test_variants_commands($pharEnv), $failures, $timings);
        } else {
            run_check_commands_sequential(build_test_variants_commands($pharEnv), $failures, $timings);
        }
    } finally {
        unset($GLOBALS['CASTOR_CHECK_AGGREGATING']);
        unset($GLOBALS['CASTOR_PHAR_READY']);
    }

    if ([] !== $failures) {
        fail_quality(format_step_failures($failures));
    }

    echo sprintf('

All suites OK (%.1fs)
', array_sum($timings));
}
