<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\run;
use function CastorTasks\build_idea_run_config_xml;
use function CastorTasks\is_llm_mode;
use function CastorTasks\persist_process_output;
use function CastorTasks\relative_report_path;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;
use function CastorTasks\summarize_deptrac_json;
use function CastorTasks\summarize_junit_xml;
use function CastorTasks\summarize_php_cs_fixer_json;
use function CastorTasks\summarize_phpstan_json;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

/**
 * Run full QA: deptrac, phpunit, controller E2E, real LLM E2E,
 * TUI snapshot E2E, phpstan, cs-fixer check.
 */
/**
 * Run full QA: deptrac, phpunit, controller E2E, real LLM E2E,
 * TUI snapshot E2E, phpstan, cs-fixer check.
 *
 * All steps run concurrently as external subprocesses (via proc_open)
 * so they do not share memory with the Castor PHAR.  Each step's
 * output is captured to var/reports/check-<step>.log.
 */
#[AsTask(description: 'Run full QA (deptrac, phpunit, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-fixer)')]
function check(): void
{
    $pharStart = hrtime(true);
    $pharPath = '';
    try {
        $pharPath = \CastorTasks\phar_ensure();
    } catch (Throwable $e) {
        echo "PHAR ensure skipped: {$e->getMessage()}
";
    }
    if ('' !== $pharPath) {
        $GLOBALS['CASTOR_PHAR_READY'] = $pharPath;
    }
    $pharDuration = (hrtime(true) - $pharStart) / 1e9;
    echo sprintf('PHAR: ok (%.1fs)

', $pharDuration);

    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';
    $phpBin = \PHP_BINARY;
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress' : '';

    // Each step is a shell command that runs the underlying tool
    // directly — not through a Castor task closure — to stay safe
    // inside the Castor PHAR (no pcntl_fork shared-memory issues).
    $allCheckCommands = [
        'deptrac' => [
            'cmd' => $phpBin.' vendor/bin/deptrac --config-file=depfile.yaml --no-progress --no-ansi'
                .(is_llm_mode() ? ' --formatter=json' : ''),
        ],
        'test' => [
            'cmd' => 'APP_ENV=test '.$pharEnv.$phpBin.' vendor/bin/phpunit'
                .' --exclude-group tui-e2e --exclude-group llm-real'
                .' '.$strictFlags.$llmFlags
                .(is_llm_mode() ? ' --log-junit='.report_path('phpunit.junit.xml') : ''),
        ],
        'test:controller' => [
            'cmd' => 'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.$phpBin.' vendor/bin/phpunit'
                .' --filter=ControllerSmokeTest'
                .' '.$strictFlags.$llmFlags
                .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-controller.junit.xml') : ''),
        ],
        'test:llm-real' => [
            'cmd' => 'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.$phpBin.' vendor/bin/phpunit'
                .' --group llm-real'
                .' '.$strictFlags.$llmFlags
                .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-llm-real.junit.xml') : ''),
        ],
        'test:tui' => [
            'cmd' => 'APP_ENV=test '.$pharEnv.$phpBin.' vendor/bin/phpunit'
                .' --group tui-e2e'
                .' '.$strictFlags.$llmFlags
                .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-tui.junit.xml') : ''),
        ],
        'phpstan' => [
            'cmd' => $phpBin.' vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress'
                .(is_llm_mode() ? ' --error-format=json --no-ansi' : ''),
        ],
        'cs-check' => [
            'cmd' => $phpBin.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --no-ansi'
                .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff'),
        ],
    ];

    // DB schema must be ready before the test / controller / llm-real
    // steps start.  Migrate once (fast, idempotent).
    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    // ── Split into LLM-dependent and non-LLM batches ──
    // test:controller, test:llm-real, and test:tui all hit
    // llama_cpp_test/test on port 9052 and must run sequentially
    // to avoid resource contention.  The remaining steps (deptrac,
    // unit tests, phpstan, cs-check) are stateless and safe to
    // parallelise.
    $parallelSafeSteps = [
        'deptrac' => $allCheckCommands['deptrac'],
        'test' => $allCheckCommands['test'],
        'phpstan' => $allCheckCommands['phpstan'],
        'cs-check' => $allCheckCommands['cs-check'],
    ];
    $llmSequentialSteps = [
        'test:controller' => $allCheckCommands['test:controller'],
        'test:llm-real' => $allCheckCommands['test:llm-real'],
        'test:tui' => $allCheckCommands['test:tui'],
    ];

    $failures = [];
    $timings = [];

    $GLOBALS['CASTOR_CHECK_AGGREGATING'] = true;
    try {
        $useParallel = \PHP_SAPI === 'cli' && function_exists('proc_open');
        if ($useParallel) {
            // Phase 1: stateless parallel steps
            run_check_commands_parallel($parallelSafeSteps, $failures, $timings);
            // Phase 2: LLM-port-dependent steps run sequentially
            run_check_commands_sequential($llmSequentialSteps, $failures, $timings);
        } else {
            run_check_commands_sequential($allCheckCommands, $failures, $timings);
        }
    } finally {
        unset($GLOBALS['CASTOR_CHECK_AGGREGATING']);
        unset($GLOBALS['CASTOR_PHAR_READY']);
    }

    if ([] !== $failures) {
        fail_quality('quality failed:'.\PHP_EOL.format_step_failures($failures));
    }

    echo 'quality: ok'.\PHP_EOL;
}
function quality(): void
{
    check();
}

/**
 * Run deptrac architecture boundary validation.
 */
#[AsTask(description: 'Run Deptrac architecture boundary validation')]
function deptrac(): void
{
    $cmd = 'vendor/bin/deptrac --config-file=depfile.yaml';

    if (!is_llm_mode()) {
        run($cmd.' --no-progress');

        return;
    }

    $process = run_quiet_command($cmd.' --formatter=json --no-progress --no-ansi');
    persist_process_output($process, 'deptrac.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('deptrac.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_deptrac_json($stdout);

    /*
     * Deptrac exits 0 even with violations (baseline-tracked). Only throw if the process
     * crashed for unrelated reasons.
     */
    if (0 !== $process->getExitCode()) {
        fail_quality(sprintf('deptrac failed (%s); report=%s; log=%s', $summary, relative_report_path('deptrac.json'), relative_report_path('deptrac.log')));
    }

    echo sprintf(
        'deptrac: ok (%s)',
        $summary,
    ).\PHP_EOL;
}

/**
 * Internal helper: get the PHAR path for test tasks.
 *
 * When check() pre-built the PHAR via CASTOR_PHAR_READY, returns it
 * immediately.  Otherwise, builds or locates it for standalone task
 * invocation (e.g. `castor test:tui` outside of `castor check`).
 */
/**
 * Internal helper: get the PHAR path for test tasks.
 *
 * When check() pre-built the PHAR via CASTOR_PHAR_READY, returns it
 * immediately.  Otherwise, builds or locates it for standalone task
 * invocation (e.g. `castor test:tui` outside of `castor check`).
 */
function phar_path_for_test_task(): string
{
    $pharPath = $GLOBALS['CASTOR_PHAR_READY'] ?? '';
    if ('' !== $pharPath) {
        return $pharPath;
    }

    try {
        return \CastorTasks\phar_ensure();
    } catch (Throwable $e) {
        echo 'PHAR ensure skipped: '.$e->getMessage().'
';

        return '';
    }
}

#[AsTask(description: 'Run PHPUnit tests (excludes tmux e2e and real LLM smoke tests); full-suite runs suites in parallel via proc_open subprocesses')]
function test(string $filter = ''): void
{
    $pharPath = phar_path_for_test_task();
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    if ('' !== $filter) {
        run_test_filtered($filter, $pharEnv);

        return;
    }

    // Full suite: run phpunit.xml.dist suites in parallel, each with
    // its own SQLite DB file to avoid contention.  Uses proc_open
    // subprocesses (not pcntl_fork) to avoid Castor PHAR corruption.
    //
    // coding-agent is the largest suite (~100s, ~99 test files) so it
    // is split into round-robin shards so slow kernel-booting tests
    // are evenly distributed across workers.
    $codingAgentShards = array_keys(coding_agent_shard_groups());
    $suites = array_merge(['agent-core'], $codingAgentShards, ['tui', 'platform']);
    $useParallel = \PHP_SAPI === 'cli' && function_exists('proc_open');
    if ($useParallel) {
        run_test_suites_parallel($suites, $pharEnv);
    } else {
        run_test_suites_sequential($suites, $pharEnv);
    }
}
function run_test_filtered(string $filter, string $pharEnv): void
{
    $dbFilename = 'app_test.sqlite';
    $dbPath = 'var/test/'.$dbFilename;

    @mkdir('var/test', 0755, true);

    // Migrate the default DB once before the filtered run.
    $migrate = run_quiet_command(
        'APP_ENV=test HATFIELD_TEST_DATABASE_PATH='.escapeshellarg($dbFilename).' '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    $dbEnv = 'HATFIELD_TEST_DATABASE_PATH='.escapeshellarg($dbFilename).' ';
    $cmd = $pharEnv.$dbEnv.'vendor/bin/phpunit --exclude-group tui-e2e --exclude-group llm-real --filter='.escapeshellarg($filter);

    if (!is_llm_mode()) {
        run($cmd.' '.phpunit_strict_issue_flags().' --colors=always');

        return;
    }

    $junitPath = report_path('phpunit.junit.xml');
    $process = run_quiet_command($cmd.' '.phpunit_strict_issue_flags().' --colors=never --no-progress --log-junit='.$junitPath);
    persist_process_output($process, 'phpunit.log');

    $summary = summarize_junit_xml($junitPath);
    $riskyInfo = phpunit_risky_summary(report_path('phpunit.log'));
    $summaryWithRisky = '' !== $riskyInfo ? $summary.', '.$riskyInfo : $summary;

    if (0 !== $process->getExitCode()) {
        fail_quality(sprintf(
            'test failed (%s); junit=%s; log=%s%s',
            $summaryWithRisky,
            relative_report_path('phpunit.junit.xml'),
            relative_report_path('phpunit.log'),
            phpunit_failure_excerpt($junitPath, 'phpunit.log'),
        ));
    }

    echo sprintf(
        'test: ok (%s); junit=%s',
        $summaryWithRisky,
        relative_report_path('phpunit.junit.xml'),
    ).\PHP_EOL;
}

/**
 * Run PHPUnit suites sequentially (fallback).
 *
 * Each suite gets its own SQLite DB file (app_test-<suite>.sqlite),
 * PHPUnit cache directory, JUnit report, and log file.  All children
 * use run_quiet_command() to avoid STDOUT interleaving; summaries
 * are printed after every child exits.
 *
 * @param list<string> $suites
 */
/**
 * Run PHPUnit suites in parallel via proc_open subprocesses.
 *
 * Each suite gets its own SQLite DB file (app_test-<suite>.sqlite),
 * PHPUnit cache directory, JUnit report, and log file.  Subprocesses
 * are spawned via proc_open so they run as genuinely independent PHP
 * processes (no shared memory / Castor PHAR corruption).
 *
 * @param list<string> $suites
 */
function run_test_suites_parallel(array $suites, string $pharEnv): void
{
    $overallStart = hrtime(true);

    @mkdir(\CastorTasks\REPORTS_DIR, 0755, true);

    echo 'Running PHPUnit suites in parallel (proc_open):
';
    foreach ($suites as $suite) {
        echo "  - {$suite}  (DB: app_test-{$suite}.sqlite)
";
    }
    echo '
';

    $commands = [];
    $logFiles = [];
    foreach ($suites as $suite) {
        $dbFilename = 'app_test-'.$suite.'.sqlite';
        $logFilename = 'phpunit-'.$suite.'.log';
        $logFiles[$suite] = report_path($logFilename);

        $commands[$suite] = [
            'cmd' => build_test_worker_command($suite, $pharEnv, $dbFilename),
            'log' => $logFiles[$suite],
        ];
    }

    // Spawn and collect results.
    $results = run_commands_parallel($commands);

    $overallDuration = (hrtime(true) - $overallStart) / 1e9;

    $failures = [];
    $timings = [];
    $suiteSummaries = [];

    echo 'Summary:
';
    foreach ($suites as $suite) {
        $result = $results[$suite] ?? ['exitCode' => -1, 'output' => 'proc_open failed', 'duration' => 0];
        $exitCode = $result['exitCode'];
        $duration = $result['duration'];
        $timings[$suite] = $duration;

        if (0 !== $exitCode) {
            $failures[$suite] = $exitCode < 0 ? 'proc_open failed' : 'exit code '.$exitCode;
        }

        $status = isset($failures[$suite]) ? 'FAIL' : 'OK';
        echo sprintf('  %-20s %s  (%.1fs)', $suite, $status, $duration);

        if (is_llm_mode()) {
            $suiteSummaries[$suite] = read_suite_junit_summary($suite);
            if ('' !== $suiteSummaries[$suite]) {
                echo "  — {$suiteSummaries[$suite]}";
            }
        } elseif (is_file($logFiles[$suite])) {
            $logContent = file_get_contents($logFiles[$suite]);
            if (false !== $logContent) {
                $lines = explode('
', trim($logContent));
                $lastLine = end($lines);
                if ('' !== $lastLine && 'OK' !== $lastLine && 'FAIL' !== $lastLine) {
                    echo "  — {$lastLine}";
                }
            }
        }
        echo '
';
    }

    echo sprintf('
Total: %.1fs
', $overallDuration);

    if ([] !== $failures) {
        $failureMsg = '';
        foreach ($failures as $suite => $reason) {
            $failureMsg .= "- {$suite}: {$reason}";
            if (isset($suiteSummaries[$suite]) && '' !== $suiteSummaries[$suite]) {
                $failureMsg .= " ({$suiteSummaries[$suite]})";
            }
            $failureMsg .= '
';
        }
        fail_quality("test failed:
{$failureMsg}");
    }

    if (is_llm_mode()) {
        $aggregate = implode(', ', array_filter($suiteSummaries, static fn (string $s): bool => '' !== $s));
        echo sprintf(
            'test: ok (%s); junit=%s',
            '' !== $aggregate ? $aggregate : 'pass',
            relative_report_path('phpunit-*.junit.xml'),
        ).\PHP_EOL;
    } else {
        echo 'test: ok
';
    }
}
/**
 * @param list<string> $suites
 */
function run_test_suites_sequential(array $suites, string $pharEnv): void
{
    $overallStart = hrtime(true);

    echo "Running PHPUnit suites sequentially (proc_open not available):\n";
    foreach ($suites as $suite) {
        echo "  - {$suite}  (DB: app_test-{$suite}.sqlite)\n";
    }
    echo "\n";

    $failures = [];
    $timings = [];
    $suiteSummaries = [];

    foreach ($suites as $suite) {
        echo sprintf('  %-20s ... ', $suite);
        $start = hrtime(true);

        $dbFilename = 'app_test-'.$suite.'.sqlite';
        $dbEnv = 'HATFIELD_TEST_DATABASE_PATH='.escapeshellarg($dbFilename).' ';
        $cacheDirEnv = 'HATFIELD_CACHE_DIR=.hatfield/cache-'.$suite.' ';

        @mkdir('var/test', 0755, true);

        // Schema migration for this worker's isolated DB file.
        $migrate = run_quiet_command(
            'APP_ENV=test '.$cacheDirEnv.$dbEnv.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
        );
        if (0 !== $migrate->getExitCode()) {
            $failures[$suite] = 'DB migration failed';
            $timings[$suite] = (hrtime(true) - $start) / 1e9;
            echo sprintf("FAIL — DB migration (%.1fs)\n", $timings[$suite]);
            continue;
        }

        $cmd = build_test_worker_command($suite, $pharEnv, $dbFilename);
        // Strip the 'migrate && ' prefix — migration already done above.
        // The prefix includes APP_ENV, HATFIELD_CACHE_DIR, dbEnv, etc.
        $cmd = preg_replace('/^APP_ENV=test .+? && /', '', $cmd);
        // Re-add dbEnv + cacheDir since we stripped the full prefix.
        $cmd = $dbEnv.$cacheDirEnv.$cmd;

        $logFilename = 'phpunit-'.$suite.'.log';
        $process = run_quiet_command($cmd);
        persist_process_output($process, $logFilename);
        $exitCode = $process->getExitCode();

        $timings[$suite] = (hrtime(true) - $start) / 1e9;

        if (0 !== $exitCode) {
            $failures[$suite] = 'exit code '.$exitCode;
        }

        if (is_llm_mode()) {
            $suiteSummaries[$suite] = read_suite_junit_summary($suite);
            echo $suiteSummaries[$suite];
        }

        echo sprintf(" (%.1fs)\n", $timings[$suite]);
    }

    $overallDuration = (hrtime(true) - $overallStart) / 1e9;
    echo sprintf("\nTotal: %.1fs\n", $overallDuration);

    // ── Failure reporting ────────────────────────────────────
    if ([] !== $failures) {
        $failureMsg = '';
        foreach ($failures as $suite => $reason) {
            $failureMsg .= "- {$suite}: {$reason}";
            if (isset($suiteSummaries[$suite]) && '' !== $suiteSummaries[$suite]) {
                $failureMsg .= " ({$suiteSummaries[$suite]})";
            }
            $failureMsg .= "\n";
        }
        fail_quality("test failed:\n{$failureMsg}");
    }

    if (is_llm_mode()) {
        $aggregate = implode(', ', array_filter($suiteSummaries, static fn (string $s): bool => '' !== $s));
        echo sprintf(
            'test: ok (%s); junit=%s',
            '' !== $aggregate ? $aggregate : 'pass',
            relative_report_path('phpunit-*.junit.xml'),
        ).\PHP_EOL;
    } else {
        echo "test: ok\n";
    }
}

/**
 * Run migration + PHPUnit for a single suite.
 *
 * Always uses run_quiet_command() so output is captured rather
 * than interleaved when multiple suites run in parallel.
 * Returns the PHPUnit exit code (0 = success).
 */
/**
 * Discover all CodingAgent test files and split them into balanced
 * round-robin shards (not subdirectory-based, which can concentrate
 * slow kernel-booting tests in one worker).
 *
 * @param int $numShards number of shards
 *
 * @return array<string, list<string>>
 */
function coding_agent_shard_groups(int $numShards = 4): array
{
    $testDir = 'tests/CodingAgent';
    $files = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

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
    $junitFilename = 'phpunit-'.$worker.'.junit.xml';
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
 * Read the JUnit summary for a previously-run suite.
 */
function read_suite_junit_summary(string $suite): string
{
    $junitPath = report_path('phpunit-'.$suite.'.junit.xml');
    if (!is_file($junitPath)) {
        return '';
    }

    return summarize_junit_xml($junitPath);
}

/**
 * Run CS fixer (fix in place).
 */
#[AsTask(description: 'Run PHP CS Fixer (fix in place)')]
function cs_fix(string $path = ''): void
{
    $cmd = 'vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php';
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }

    if (!is_llm_mode()) {
        run($cmd);

        return;
    }

    $process = run_quiet_command($cmd.' --format=json --show-progress=none --no-ansi');
    persist_process_output($process, 'php-cs-fixer.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('php-cs-fixer.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_php_cs_fixer_json($stdout);

    if (0 !== $process->getExitCode()) {
        fail_quality(sprintf('cs-fix failed (%s); report=%s; log=%s', $summary, relative_report_path('php-cs-fixer.json'), relative_report_path('php-cs-fixer.log')));
    }

    echo sprintf(
        'cs-fix: ok (%s)',
        $summary,
    ).\PHP_EOL;
}

/**
 * Run CS fixer dry-run (check only).
 */
#[AsTask(description: 'Run PHP CS Fixer (dry-run, check only)')]
function cs_check(string $path = ''): void
{
    $cmd = 'vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run';
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }

    if (!is_llm_mode()) {
        run($cmd.' --diff');

        return;
    }

    $process = run_quiet_command($cmd.' --format=json --show-progress=none --no-ansi');
    persist_process_output($process, 'php-cs-fixer-check.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('php-cs-fixer-check.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_php_cs_fixer_json($stdout);

    if (0 !== $process->getExitCode()) {
        fail_quality(sprintf('cs-check failed (%s); report=%s; log=%s', $summary, relative_report_path('php-cs-fixer-check.json'), relative_report_path('php-cs-fixer-check.log')));
    }

    echo sprintf(
        'cs-check: ok (%s)',
        $summary,
    ).\PHP_EOL;
}

/**
 * Run PHPStan static analysis.
 */
#[AsTask(description: 'Run PHPStan static analysis')]
function phpstan(string $path = ''): void
{
    $cmd = 'vendor/bin/phpstan analyse -c phpstan.dist.neon';
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }

    if (!is_llm_mode()) {
        run($cmd.' --no-progress');

        return;
    }

    $process = run_quiet_command($cmd.' --error-format=json --no-progress --no-ansi');
    persist_process_output($process, 'phpstan.log');

    $stdout = trim($process->getOutput());
    if ('' !== $stdout) {
        file_put_contents(report_path('phpstan.json'), $stdout.\PHP_EOL);
    }

    $summary = summarize_phpstan_json($stdout);

    /*
     * PHPStan exits 1 when there are any file errors, including pre-existing baseline
     * errors. Throw when errors or file_errors are present so the agent sees failures.
     */
    $decoded = json_decode($stdout, true);
    $hasErrors = ($decoded['totals']['errors'] ?? 0) > 0;
    $hasFileErrors = ($decoded['totals']['file_errors'] ?? 0) > 0;
    if ($hasErrors || $hasFileErrors) {
        fail_quality(sprintf(
            'phpstan failed (%s); report=%s; log=%s%s',
            $summary,
            relative_report_path('phpstan.json'),
            relative_report_path('phpstan.log'),
            phpstan_failure_excerpt($stdout),
        ));
    }

    echo sprintf(
        'phpstan: ok (%s)',
        $summary,
    ).\PHP_EOL;
}

/**
 * Regenerate the PHPStan baseline file.
 */
#[AsTask(name: 'phpstan:baseline', description: 'Regenerate PHPStan baseline file')]
function phpstan_baseline(): void
{
    run('vendor/bin/phpstan analyse -c phpstan.dist.neon --generate-baseline phpstan-baseline.neon --no-progress');
}

/**
 * Remove generated QA caches and clear Symfony cache.
 */
#[AsTask(name: 'cache:clear', description: 'Remove generated QA caches and clear Symfony cache')]
function cache_clear(): void
{
    $files = [
        __DIR__.'/../.deptrac.cache',
        __DIR__.'/../.php-cs-fixer.cache',
    ];
    $dirs = [
        __DIR__.'/../var/phpstan',
        __DIR__.'/../var/cache',
    ];

    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            echo 'Removed '.basename($file).\PHP_EOL;
        }
    }

    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
            }
            rmdir($dir);
            echo 'Removed '.basename($dir).' directory'.\PHP_EOL;
        }
    }

    echo 'cache:clear done'."\n";
}

/**
 * Remove all temp/test artifacts.
 *
 * Cleans up var/tmp/ (test isolation dirs, PHAR builds, smoke dirs),
 * var/cache/ (Symfony cache), var/logs/ (Monolog logs), and
 * var/reports/ (QA report artifacts).  Safe to run anytime — only removes
 * generated/transient files, never tracked sources or .gitkeep.
 */
#[AsTask(name: 'cleanup', description: 'Remove all temp/test artifacts (var/tmp/*, var/cache/, var/logs/, var/reports/, .hatfield/cache-*, var/test/app_test*.sqlite)')]
function cleanup(): void
{
    $root = realpath(__DIR__.'/..');
    $tmpDir = $root.'/var/tmp';

    $removed = 0;

    // ── var/tmp subdirectories and glob patterns ──
    $tmpPatterns = [
        'tui-e2e-*',
        'tui-failures',
        'phar',
        'phar-build',
        'run-agent-test-*',
        'hatfield-llamacpp-*',
        'test-*',
    ];

    foreach ($tmpPatterns as $pattern) {
        $paths = glob($tmpDir.'/'.$pattern, \GLOB_ONLYDIR);
        if (false === $paths) {
            continue;
        }
        foreach ($paths as $path) {
            rmtree($path);
            ++$removed;
            echo 'Removed '.str_replace($root.'/', '', $path)."\n";
        }
    }

    // ── Parallel worker cache dirs (per-shard Symfony container cache) ──
    $parallelCachePatterns = glob($root.'/.hatfield/cache-*', \GLOB_ONLYDIR);
    if (false !== $parallelCachePatterns) {
        foreach ($parallelCachePatterns as $path) {
            rmtree($path);
            ++$removed;
            echo 'Removed '.str_replace($root.'/', '', $path)."\n";
        }
    }

    // ── PHAR build lock file ──
    $pharLock = $root.'/var/tmp/phar-build.lock';
    if (is_file($pharLock)) {
        unlink($pharLock);
        ++$removed;
        echo "Removed var/tmp/phar-build.lock\n";
    }

    // ── var/test DB files (including per-worker shard DBs) ──
    $testDbFiles = glob($root.'/var/test/app_test*.sqlite');
    if (false !== $testDbFiles) {
        foreach ($testDbFiles as $file) {
            unlink($file);
            ++$removed;
            echo 'Removed '.str_replace($root.'/', '', $file)."\n";
        }
    }

    // ── Top-level generated directories ──
    $topDirs = [
        'var/cache',
        'var/logs',
        'var/reports',
    ];

    foreach ($topDirs as $dir) {
        $full = $root.'/'.$dir;
        if (is_dir($full)) {
            rmtree($full);
            ++$removed;
            echo 'Removed '.$dir."\n";
        }
    }

    // ── System temp test artifacts (outside var/tmp/, in /tmp) ──
    $sysTmpPatterns = [
        'hatfield-auth-test-*',
        'hatfield-oauth-test-*',
        'hatfield-phar-smoke-*',
        'agent-core-soak-*',
        'agent-core-structured-log-*',
        'skills_registry_test_*',
        'skills_builder_test_*',
        'skills_discovery_test_*',
        'agents_context_test_*',
        'hatfield-session-runstore-*',
        'hatfield-session-eventstore-*',
        'hatfield-aggregate-resume-*',
        'phar-cache-hash-test-*',
        'hatfield-llamacpp-*',
    ];

    $sysTmp = sys_get_temp_dir();
    foreach ($sysTmpPatterns as $pattern) {
        $paths = glob($sysTmp.'/'.$pattern, \GLOB_ONLYDIR);
        if (false === $paths) {
            continue;
        }
        foreach ($paths as $path) {
            rmtree($path);
            ++$removed;
            echo 'Removed '.$path."\n";
        }
    }

    if (0 === $removed) {
        echo 'cleanup: nothing to remove'."\n";
    } else {
        echo "\ncleanup: removed {$removed} items\n";
    }
}

/**
 * Install dependencies.
 */
#[AsTask(description: 'Install dependencies')]
function install(): void
{
    run('composer install --no-interaction');
}

/**
 * Generate IntelliJ/PhpStorm run configurations for no-argument Castor tasks.
 */
#[AsTask(name: 'idea:run-configs', description: 'Generate PhpStorm run configurations for Castor tasks')]
function idea_run_configs(): void
{
    $configs = [
        'check' => 'Run full QA (deptrac, phpunit, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-fixer).',
        'quality' => 'Alias for check.',
        'install' => 'Install Composer dependencies.',
        'deptrac' => 'Run Deptrac architecture boundary validation.',
        'test' => 'Run PHPUnit tests excluding tmux e2e and LLM smoke.',
        'test:tui' => 'Run TUI e2e snapshot tests.',
        'test:tui-update' => 'Run TUI e2e tests and update golden snapshots.',
        'test:llm-real' => 'Run real llama.cpp smoke test.',
        'phpstan' => 'Run PHPStan static analysis.',
        'phpstan:baseline' => 'Regenerate PHPStan baseline file.',
        'cs-fix' => 'Run PHP CS Fixer and modify files in place.',
        'cs-check' => 'Run PHP CS Fixer dry-run check.',
        'cache:clear' => 'Remove generated QA caches and clear Symfony cache.',
        'cleanup' => 'Remove all temp/test artifacts (var/tmp/*, var/cache/, var/logs/, var/reports/, .hatfield/cache-*, var/test/app_test*.sqlite).',
        'log:tail' => 'Show recent log entries.',
        'log:search' => 'Search log entries.',
        'log:files' => 'List log files.',
        'log:clear' => 'Remove old rotated logs.',
        'datadog:status' => 'Show local Datadog readiness for Hatfield logs/APM.',
        'datadog:log-config' => 'Print the Datadog Agent Hatfield log config and install hints.',
        'datadog:smoke-log' => 'Write a Datadog log collection smoke-test line.',
        'run:agent' => 'Launch the agent TUI in tmux.',
        'run:agent-datadog' => 'Launch the agent TUI with Datadog APM enabled.',
        'run:agent-test' => 'Launch deterministic tmux session for snapshot testing.',
        'idea:run-configs' => 'Regenerate PhpStorm run configurations for Castor tasks.',
    ];

    $dir = __DIR__.'/../.idea/runConfigurations';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Unable to create run configuration directory "%s".', $dir));
    }

    foreach ($configs as $commandName => $description) {
        $file = $dir.'/'.idea_run_config_filename($commandName);
        file_put_contents($file, build_idea_run_config_xml($commandName, $description));
        echo 'Wrote '.relative_to_project($file).\PHP_EOL;
    }
}

function idea_run_config_filename(string $commandName): string
{
    return 'castor_'.preg_replace('/[^A-Za-z0-9_.-]+/', '_', str_replace(':', '_', $commandName)).'.xml';
}

function relative_to_project(string $path): string
{
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        return $path;
    }

    $resolved = realpath($path);
    $realPath = false !== $resolved ? $resolved : $path;
    $relative = preg_replace('#^'.preg_quote($root.'/', '#').'#', '', $realPath);

    return $relative ?? $realPath;
}

/**
 * Remove a task worktree by slug, branch suffix, absolute path, or relative path.
 *
 * Examples:
 *   castor worktree:remove 2026-05-16-add-code-review-pr-workflow-and-update-task-tool --force
 *   castor worktree:remove task/2026-05-16-add-code-review-pr-workflow-and-update-task-tool --delete-branch --force
 */
#[AsTask(name: 'worktree:remove', description: 'Remove a git worktree by slug/path and optionally delete its branch')]
function worktree_remove(string $target, bool $force = false, bool $deleteBranch = false): void
{
    $root = realpath(__DIR__.'/..');
    if (false === $root) {
        throw new RuntimeException('Unable to resolve repository root.');
    }

    $worktrees = git_worktrees($root);
    $match = resolve_worktree_target($root, $worktrees, $target);
    $path = $match['path'];
    $branch = $match['branch'] ?? null;

    if ($path === $root) {
        throw new RuntimeException('Refusing to remove the integration checkout.');
    }

    [$statusCode, $statusOutput] = run_capture(sprintf('git -C %s status --short', escapeshellarg($path)));
    if (0 !== $statusCode) {
        throw new RuntimeException("Unable to inspect worktree status:\n".$statusOutput);
    }

    if ('' !== trim($statusOutput) && !$force) {
        throw new RuntimeException(sprintf("Worktree has uncommitted or untracked files. Re-run with --force to remove it.\n%s\n\n%s", $path, $statusOutput));
    }

    [$removeCode, $removeOutput] = run_capture(sprintf(
        'git -C %s worktree remove %s %s',
        escapeshellarg($root),
        $force ? '--force' : '',
        escapeshellarg($path),
    ));
    if (0 !== $removeCode) {
        throw new RuntimeException("Failed to remove worktree {$path}:\n".$removeOutput);
    }

    echo "Removed worktree {$path}.\n";

    if ($deleteBranch && null !== $branch) {
        $branchName = preg_replace('#^refs/heads/#', '', $branch) ?? $branch;
        [$deleteCode, $deleteOutput] = run_capture(sprintf(
            'git -C %s branch -D %s',
            escapeshellarg($root),
            escapeshellarg($branchName),
        ));
        if (0 !== $deleteCode) {
            throw new RuntimeException("Worktree removed, but failed to delete branch {$branchName}:\n".$deleteOutput);
        }

        echo "Deleted branch {$branchName}.\n";
    }
}

/**
 * @return array{0: int, 1: string}
 */
function run_capture(string $command): array
{
    $output = [];
    exec($command.' 2>&1', $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

/**
 * @return list<array{path: string, branch?: string}>
 */
function git_worktrees(string $root): array
{
    [$code, $output] = run_capture(sprintf('git -C %s worktree list --porcelain', escapeshellarg($root)));
    if (0 !== $code) {
        throw new RuntimeException("Unable to list git worktrees:\n".$output);
    }

    $worktrees = [];
    $current = null;
    foreach (explode("\n", $output) as $line) {
        if (str_starts_with($line, 'worktree ')) {
            if (null !== $current) {
                $worktrees[] = $current;
            }
            $current = ['path' => substr($line, 9)];
            continue;
        }

        if (null !== $current && str_starts_with($line, 'branch ')) {
            $current['branch'] = substr($line, 7);
        }
    }

    if (null !== $current) {
        $worktrees[] = $current;
    }

    return $worktrees;
}

/**
 * @param list<array{path: string, branch?: string}> $worktrees
 *
 * @return array{path: string, branch?: string}
 */
function resolve_worktree_target(string $root, array $worktrees, string $target): array
{
    $candidatePath = str_starts_with($target, '/') ? $target : realpath($root.'/'.$target);
    $matches = [];

    foreach ($worktrees as $worktree) {
        $path = $worktree['path'];
        $branch = $worktree['branch'] ?? '';
        $branchName = preg_replace('#^refs/heads/#', '', $branch) ?? $branch;

        if (
            $target === $path
            || (false !== $candidatePath && $candidatePath === $path)
            || basename($path) === $target
            || $branchName === $target
            || str_ends_with($branchName, '/'.$target)
        ) {
            $matches[] = $worktree;
        }
    }

    if ([] === $matches) {
        throw new RuntimeException(sprintf("No git worktree matched '%s'.\n\nKnown worktrees:\n%s", $target, implode("\n", array_map(static fn (array $worktree): string => '- '.$worktree['path'].' '.($worktree['branch'] ?? ''), $worktrees))));
    }

    if (count($matches) > 1) {
        throw new RuntimeException(sprintf("Worktree target '%s' is ambiguous:\n%s", $target, implode("\n", array_map(static fn (array $worktree): string => '- '.$worktree['path'].' '.($worktree['branch'] ?? ''), $matches))));
    }

    return $matches[0];
}

// ─── PHAR tasks ────────────────────────────────────────────────

/**
 * Build the PHAR (worktree-local by default) using box.
 *
 * Requires humbug/box to be installed globally or available via PATH
 * or the BOX_BIN environment variable.
 */
#[AsTask(name: 'phar:build', description: 'Build hatfield.phar (worktree-local by default)')]
function phar_build(): void
{
    \CastorTasks\phar_build();
}

/**
 * Ensure the PHAR exists (builds if missing).
 *
 * Can be added as a dependency to run/test tasks so the PHAR is always
 * available before subprocess spawning.
 */
#[AsTask(name: 'phar:ensure', description: 'Ensure worktree-local hatfield.phar exists (build if missing)')]
function phar_ensure(): void
{
    \CastorTasks\phar_ensure();
}

/**
 * Remove the built PHAR.
 */
#[AsTask(name: 'phar:clean', description: 'Remove worktree-local hatfield.phar')]
function phar_clean(): void
{
    $pharPath = \CastorTasks\hatfield_phar_path();
    if (is_file($pharPath)) {
        unlink($pharPath);
        echo "Removed {$pharPath}\n";
    } else {
        echo "No PHAR found at {$pharPath}\n";
    }
    echo 'PHAR cleaned.'."\n";
}

// ─── tmux TUI tasks ───────────────────────────────────────

// ─── tmux TUI e2e test tasks ─────────────────────────────

/**
 * Run PHPUnit TUI e2e tests (requires tmux).
 *
 * These tests launch the agent in detached tmux sessions
 * and compare snapshots against golden fixtures.  They are
 * included in "castor check" because TUI/runtime behavior must
 * be validated with real user-visible workflows.
 */
#[AsTask(name: 'test:tui', description: 'Run TUI e2e snapshot tests (requires tmux), using the built PHAR')]
function test_tui(string $filter = ''): void
{
    $pharPath = phar_path_for_test_task();
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $filterFlag = '' !== $filter ? ' --filter='.escapeshellarg($filter) : '';

    if (is_llm_mode()) {
        run_quality_step('test:tui', $pharEnv.'vendor/bin/phpunit --group tui-e2e'.$filterFlag, 'phpunit-tui.junit.xml', 'phpunit-tui.log');

        return;
    }

    run($pharEnv.'vendor/bin/phpunit '.phpunit_strict_issue_flags().' --group tui-e2e'.$filterFlag.' --colors=always');
}

/**
 * Run the opt-in real llama.cpp smoke test.
 *
 * Uses project Hatfield settings by default:
 *   ai.providers.llama_cpp.base_url
 *   ai.providers.llama_cpp.models
 *
 * Optional environment overrides:
 *   LLAMA_CPP_BASE_URL   (e.g. http://192.168.2.38:8052/v1)
 *   LLAMA_CPP_MODEL      (optional, default: first configured model or flash)
 *   LLAMA_CPP_API_KEY    (optional, default: configured api_key or dummy)
 */
#[AsTask(name: 'test:llm-real', description: 'Run opt-in real llama.cpp smoke test against configured llama_cpp provider')]
function test_llm_real(string $filter = ''): void
{
    $pharPath = phar_path_for_test_task();
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $filterFlag = '' !== $filter ? ' --filter='.escapeshellarg($filter) : '';

    if (is_llm_mode()) {
        run_quality_step('test:llm-real', $pharEnv.'LLAMA_CPP_SMOKE_TEST=1 vendor/bin/phpunit --group llm-real'.$filterFlag, 'phpunit-llm-real.junit.xml', 'phpunit-llm-real.log');

        return;
    }

    run($pharEnv.'LLAMA_CPP_SMOKE_TEST=1 vendor/bin/phpunit '.phpunit_strict_issue_flags().' --group llm-real'.$filterFlag.' --colors=always');
}

/**
 * Run the controller E2E smoke test.
 *
 * Spawns `agent --controller` as a subprocess, sends JSONL commands
 * over stdin, reads JSONL events from stdout, and asserts the full
 * async runtime pipeline (controller event loop -> messenger consumers
 * -> LLM invocation -> event delivery).
 *
 * Uses the fast llama_cpp_test/test model on port 9052.
 * Same fast test model as test:llm-real and TUI E2E tests.
 */
#[AsTask(name: 'test:controller', description: 'Run controller E2E smoke test (spawns --controller, sends JSONL)')]
function test_controller(string $filter = ''): void
{
    $pharPath = phar_path_for_test_task();
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $filterFlag = ' --filter='.escapeshellarg('' !== $filter ? $filter : 'ControllerSmokeTest');

    if (is_llm_mode()) {
        run_quality_step('test:controller', $pharEnv.'LLAMA_CPP_SMOKE_TEST=1 vendor/bin/phpunit'.$filterFlag, 'phpunit-controller.junit.xml', 'phpunit-controller.log');

        return;
    }

    run($pharEnv.'LLAMA_CPP_SMOKE_TEST=1 vendor/bin/phpunit '.phpunit_strict_issue_flags().$filterFlag.' --colors=always');
}

#[AsTask(name: 'test:tui-update', description: 'Run TUI e2e tests and update golden snapshots')]
function test_tui_update(string $filter = ''): void
{
    $pharPath = phar_path_for_test_task();
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $filterFlag = '' !== $filter ? ' --filter='.escapeshellarg($filter) : '';

    if (is_llm_mode()) {
        run_quality_step('test:tui-update', $pharEnv.'HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --group tui-e2e'.$filterFlag, 'phpunit-tui-update.junit.xml', 'phpunit-tui-update.log');

        return;
    }

    run($pharEnv.'HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit '.phpunit_strict_issue_flags().' --group tui-e2e'.$filterFlag.' --colors=always');
}

/**
 * Check that tmux is available.
 */
function check_tmux(): void
{
    $which = trim(shell_exec('which tmux 2>/dev/null') ?? '');
    if ('' === $which) {
        throw new RuntimeException('tmux is not installed. Install it with your package manager before using run:* tasks.');
    }
}

/**
 * Run an interactive/fullscreen command directly on the caller's TTY.
 *
 * Castor\run() is ideal for QA commands, but it launches through Symfony
 * Process pipes. That can break tmux attach sessions: terminal size falls
 * back to 80x24 and raw key sequences/control keys may not pass through
 * cleanly. Use passthru() for commands that must own the terminal.
 */
function run_interactive(string $command): void
{
    passthru($command, $exitCode);

    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('Interactive command failed with exit code %d: %s', $exitCode, $command));
    }
}

/**
 * Whether the default launcher should enable Datadog APM for the spawned
 * agent process.
 *
 * Auto mode enables APM only when ddtrace is installed and a local Agent trace
 * endpoint is reachable. Set HATFIELD_DATADOG=0 to force-disable or
 * HATFIELD_DATADOG=1 to force-enable when ddtrace is loaded.
 */
function datadog_auto_enabled(): bool
{
    $flag = getenv('HATFIELD_DATADOG');
    if (false !== $flag) {
        return in_array(strtolower($flag), ['1', 'true', 'yes', 'on'], true) && extension_loaded('ddtrace');
    }

    if (!extension_loaded('ddtrace')) {
        return false;
    }

    if (false !== getenv('DD_TRACE_ENABLED') && in_array(strtolower((string) getenv('DD_TRACE_ENABLED')), ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return datadog_trace_endpoint_available();
}

function datadog_is_unix_socket(string $path): bool
{
    return file_exists($path) && 'socket' === @filetype($path);
}

function datadog_trace_endpoint_available(): bool
{
    $agentUrl = getenv('DD_TRACE_AGENT_URL');
    if (is_string($agentUrl) && str_starts_with($agentUrl, 'unix://')) {
        return datadog_is_unix_socket(substr($agentUrl, strlen('unix://')));
    }

    if (datadog_is_unix_socket('/var/run/datadog/apm.socket')) {
        return true;
    }

    $host = (false !== ($_host = getenv('DD_AGENT_HOST')) ? $_host : '127.0.0.1');
    $port = (int) (false !== ($_port = getenv('DD_TRACE_AGENT_PORT')) ? $_port : '8126');
    $socket = @fsockopen((string) $host, $port, $errno, $errstr, 0.1);
    if (is_resource($socket)) {
        fclose($socket);

        return true;
    }

    return false;
}

/**
 * Environment prefix for Datadog APM opt-in/opt-out when launching PHP.
 *
 * ddtrace reads its settings before userland PHP boots, so these values must
 * be present in the shell environment that starts `php bin/console`.
 */
function datadog_env_command(bool $enabled): string
{
    $vars = [
        'DD_TRACE_ENABLED' => $enabled ? '1' : '0',
        'DD_TRACE_CLI_ENABLED' => $enabled ? '1' : '0',
    ];

    if ($enabled) {
        $version = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?? '');
        $vars += [
            'DD_SERVICE' => (false !== ($_svc = getenv('DD_SERVICE')) ? $_svc : 'hatfield'),
            'DD_ENV' => (false !== ($_env = getenv('DD_ENV')) ? $_env : 'dev'),
            'DD_VERSION' => '' !== $version ? $version : 'local',
            'DD_LOGS_INJECTION' => 'true',
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS' => 'true',
        ];

        if (datadog_is_unix_socket('/var/run/datadog/apm.socket')) {
            $vars['DD_TRACE_AGENT_URL'] = 'unix:///var/run/datadog/apm.socket';
        }
    }

    $parts = ['env'];
    foreach ($vars as $name => $value) {
        $parts[] = $name.'='.escapeshellarg($value);
    }

    return implode(' ', $parts);
}

/**
 * Launch the agent TUI in a tmux session.
 *
 * Inside tmux: creates a new window named "hatfield-agent".
 * Outside tmux: creates or attaches to a session named "hatfield-agent".
 *
 * No relaunch loop — the TUI runs once and exits naturally.
 */
#[AsTask(name: 'run:agent', description: 'Launch the agent TUI in a tmux session (hatfield-agent), using the built PHAR')]
function run_agent(): void
{
    check_tmux();

    $phar = \CastorTasks\phar_ensure();

    $root = realpath(__DIR__.'/..');
    $session = 'hatfield-agent';
    $insideTmux = false !== getenv('TMUX');

    // Force APP_ENV=prod — the PHAR is a production artifact with no dev
    // dependencies. Inheriting APP_ENV=dev from Castor's .env loading would
    // cause the PHAR to reuse stale source-checkout dev caches, which embed
    // filesystem vendor paths that collide with the PHAR's bundled autoloader.
    $innerCmd = sprintf(
        'cd %s && APP_ENV=prod exec %s php %s agent',
        escapeshellarg($root),
        datadog_env_command(datadog_auto_enabled()),
        escapeshellarg($phar),
    );

    if ($insideTmux) {
        shell_exec(sprintf(
            'tmux new-window -n %s bash -c %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        ));
        echo "Created tmux window '{$session}'.\n";
    } else {
        run_interactive(sprintf(
            'tmux new-session -A -s %s bash -lc %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        ));
    }
}

/**
 * Launch the agent TUI with Datadog APM/traces enabled for this run.
 */
#[AsTask(name: 'run:agent-datadog', description: 'Launch the agent TUI with Datadog APM enabled, using the built PHAR')]
function run_agent_datadog(): void
{
    check_tmux();

    $phar = \CastorTasks\phar_ensure();

    $root = realpath(__DIR__.'/..');
    $session = 'hatfield-agent-datadog';
    $insideTmux = false !== getenv('TMUX');

    $innerCmd = sprintf(
        'cd %s && APP_ENV=prod exec %s php %s agent',
        escapeshellarg($root),
        datadog_env_command(true),
        escapeshellarg($phar),
    );

    if ($insideTmux) {
        shell_exec(sprintf(
            'tmux new-window -n %s bash -c %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        ));
        echo "Created tmux window '{$session}'.\n";
    } else {
        run_interactive(sprintf(
            'tmux new-session -A -s %s bash -lc %s',
            escapeshellarg($session),
            escapeshellarg($innerCmd)
        ));
    }
}

/**
 * Launch a deterministic tmux session for snapshot testing.
 *
 * Creates a fixed-size detached session, runs the agent with a known
 * prompt, waits for it to render, captures a plain-text snapshot to
 * .hatfield/tmp/tui/latest.txt, and prints inspection commands.
 *
 * The session remains alive because the TUI event loop blocks until
 * the user exits (Ctrl+D or double Ctrl+C).
 * Re-running this task tears down and recreates the session from
 * scratch.
 */
#[AsTask(name: 'run:agent-test', description: 'Launch a deterministic tmux session for snapshot testing')]
function run_agent_test(): void
{
    check_tmux();

    $root = realpath(__DIR__.'/..');
    $session = 'hatfield-agent-test';
    $width = 120;
    $height = 40;
    $testDir = sprintf('%s/var/tmp/run-agent-test-%s', $root, bin2hex(random_bytes(6)));
    $homeDir = $testDir.'/home';

    mkdir($testDir.'/.hatfield', 0777, true);
    mkdir($homeDir.'/.hatfield', 0777, true);

    $projectSettings = $root.'/.hatfield/settings.yaml';
    if (is_readable($projectSettings)) {
        $settings = (string) file_get_contents($projectSettings);
        $settings = preg_replace(
            '/^ai:\n/m',
            "ai:\n    default_model: llama_cpp_test/test\n    default_reasoning: off\n",
            $settings,
            1,
        ) ?? $settings;
        file_put_contents($testDir.'/.hatfield/settings.yaml', $settings);
        file_put_contents($homeDir.'/.hatfield/settings.yaml', $settings);
    }

    $snapDir = $testDir.'/.hatfield/tmp/tui';
    $snapPath = $snapDir.'/latest.txt';
    $metaPath = $snapDir.'/agent-test.env';

    if (!is_dir($snapDir)) {
        mkdir($snapDir, 0777, true);
    }

    // Tear down any previous test session
    shell_exec(sprintf('tmux kill-session -t %s 2>/dev/null', escapeshellarg($session)));

    $phar = \CastorTasks\phar_ensure();

    $innerCmd = sprintf(
        'cd %s && APP_ENV=dev HOME=%s %s %s %s agent --prompt=%s --model=llama_cpp_test/test --reasoning=off 2>&1',
        escapeshellarg($testDir),
        escapeshellarg($homeDir),
        datadog_env_command(false),
        escapeshellarg(\PHP_BINARY),
        escapeshellarg($phar),
        escapeshellarg('Say exactly: hello')
    );

    // Launch detached, capturing the pane id.
    $cmd = sprintf(
        'tmux new-session -d -P -F "#{pane_id}" -x %d -y %d -s %s -- bash -c %s 2>&1',
        $width,
        $height,
        escapeshellarg($session),
        escapeshellarg($innerCmd)
    );

    $output = shell_exec($cmd);
    $paneId = trim($output ?? '');

    if ('' === $paneId) {
        throw new RuntimeException("Failed to create tmux session.\nCommand: {$cmd}\nOutput: ".($output ?? '<none>'));
    }

    shell_exec(sprintf(
        'tmux resize-window -t %s -x %d -y %d 2>/dev/null',
        escapeshellarg($session),
        $width,
        $height
    ));

    $deadline = microtime(true) + 180.0;
    $snapshot = '';
    $matched = false;
    while (microtime(true) < $deadline) {
        $snapshot = shell_exec(sprintf('tmux capture-pane -p -t %s 2>&1', escapeshellarg($paneId))) ?? '';
        file_put_contents($snapPath, $snapshot);

        if (str_contains($snapshot, '◇') || str_contains($snapshot, '✕') || str_contains(strtolower($snapshot), 'runtime error')) {
            $matched = true;
            break;
        }

        $paneCheck = [];
        exec(sprintf('tmux display-message -p -t %s "#{pane_id}" 2>/dev/null', escapeshellarg($paneId)), $paneCheck, $paneExit);
        if (0 !== $paneExit) {
            throw new RuntimeException("Agent tmux pane exited before response.\n".agent_test_diagnostics($testDir, $snapshot));
        }

        usleep(250_000);
    }

    if (!$matched) {
        throw new RuntimeException("Timed out waiting for assistant/error block.\n".agent_test_diagnostics($testDir, $snapshot));
    }

    $meta = [
        'session' => $session,
        'pane_id' => $paneId,
        'width' => $width,
        'height' => $height,
        'snapshot_path' => $snapPath,
        'test_dir' => $testDir,
        'root' => $root,
        'created_at' => date('c'),
    ];
    file_put_contents($metaPath, json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

    echo 'Test session:  '.$session."\n";
    echo 'Pane:         '.$paneId."\n";
    echo 'Snapshot:     '.$snapPath."\n";
    echo 'Metadata:     '.$metaPath."\n";
    echo 'Test CWD:     '.$testDir."\n";
    echo "\n";
    echo "Latest snapshot:\n".$snapshot."\n";
    echo "\n";
    echo 'Inspect:      tmux attach-session -t '.$session."\n";
    echo 'Plain snap:   tmux capture-pane -p -t '.$paneId."\n";
    echo 'ANSI snap:    tmux capture-pane -p -e -t '.$paneId."\n";
    echo 'Tear down:    tmux kill-session -t '.$session."\n";
}

function agent_test_diagnostics(string $testDir, string $snapshot): string
{
    $out = "Test CWD: {$testDir}\n\nPlain snapshot:\n{$snapshot}\n\n";

    $messenger = $testDir.'/.hatfield/messenger.sqlite';
    $out .= sprintf("Messenger DB: %s (%s)\n\n", $messenger, is_file($messenger) ? filesize($messenger).' bytes' : 'missing');

    $logFiles = glob($testDir.'/.hatfield/logs/*.log');
    if (false === $logFiles) {
        $logFiles = [];
    }
    foreach ($logFiles as $logFile) {
        $lines = explode("\n", (string) file_get_contents($logFile));
        $out .= "--- log {$logFile} tail ---\n".implode("\n", array_slice($lines, -80))."\n\n";
    }

    $sessionDirs = glob($testDir.'/.hatfield/sessions/*', \GLOB_ONLYDIR);
    if (false === $sessionDirs) {
        $sessionDirs = [];
    }
    foreach ($sessionDirs as $sessionDir) {
        $out .= "Session: {$sessionDir}\n";
        foreach (['state.json', 'events.jsonl', 'idempotency.jsonl'] as $file) {
            $path = $sessionDir.'/'.$file;
            $out .= is_file($path)
                ? "--- {$file} ---\n".(string) file_get_contents($path)."\n\n"
                : "--- {$file}: missing ---\n\n";
        }
    }

    return $out;
}

/**
 * @param array<string, string> $failures
 */
function format_step_failures(array $failures): string
{
    $lines = [];
    foreach ($failures as $step => $message) {
        $lines[] = '- '.$step.': '.str_replace("\n", "\n  ", $message);
    }

    return implode("\n", $lines);
}

function fail_quality(string $message): never
{
    $isAggregating = isset($GLOBALS['CASTOR_CHECK_AGGREGATING']) && true === $GLOBALS['CASTOR_CHECK_AGGREGATING'];
    if (is_llm_mode() && !$isAggregating) {
        fwrite(\STDERR, $message.\PHP_EOL);
        exit(1);
    }

    throw new RuntimeException($message);
}

function phpunit_risky_summary(string $logPath): string
{
    if (!is_file($logPath) || !is_readable($logPath)) {
        return '';
    }

    $log = (string) file_get_contents($logPath);
    if ('' === $log) {
        return '';
    }

    if (!preg_match('/There were (\d+) risky tests?:/s', $log, $countMatch)) {
        return '';
    }

    $riskyCount = (int) $countMatch[1];
    if (0 === $riskyCount) {
        return '';
    }

    if (!preg_match('/There were \d+ risky tests?:.*?(?=\n\nOK, |\n\nFAILURES!|\n\nERRORS!|\z)/s', $log, $blockMatch)) {
        return 'risky='.$riskyCount;
    }

    $block = trim($blockMatch[0]);
    $names = [];
    foreach (explode("\n", $block) as $line) {
        if (preg_match('/^\d+\)\s+(.+)/', $line, $nameMat)) {
            $names[] = trim($nameMat[1]);
        }
    }

    $excerpt = 'risky='.$riskyCount;
    if ([] !== $names) {
        $excerpt .= '; '.implode(', ', array_slice($names, 0, 5));
        if (count($names) > 5) {
            $excerpt .= ' (+'.(count($names) - 5).' more)';
        }
    }

    return $excerpt;
}

/**
 * Strict PHPUnit flags applied to every Castor PHPUnit invocation (both
 * LLM and interactive modes) so deprecations, notices, warnings, risky
 * tests, and PHPUnit diagnostic issues produce non-zero exits rather
 * than being silently tolerated.
 *
 * PHPUnit 13 supports --fail-on-all-issues --display-all-issues which
 * covers every category (risky, warnings, notices, deprecations,
 * PHPUnit-internal deprecations/notices/warnings).
 */
function phpunit_strict_issue_flags(): string
{
    return '--fail-on-all-issues --display-all-issues';
}

function run_quality_step(string $stepName, string $command, string $junitFilename, string $logFilename): void
{
    $junitPath = report_path($junitFilename);
    $logPath = report_path($logFilename);
    @mkdir(dirname($junitPath), 0755, true);

    $process = run_quiet_command($command.' '.phpunit_strict_issue_flags().' --colors=never --no-progress --log-junit='.$junitPath);
    persist_process_output($process, $logFilename);

    $summary = summarize_junit_xml($junitPath);

    $summaryWithRisky = $summary;
    $riskySummary = phpunit_risky_summary($logPath);
    if ('' !== $riskySummary) {
        $summaryWithRisky = $summary.', '.$riskySummary;
    }

    if (0 !== $process->getExitCode()) {
        $excerpt = phpunit_failure_excerpt($junitPath, $logFilename);
        fail_quality(sprintf(
            '%s failed (%s); junit=%s; log=%s%s',
            $stepName,
            $summaryWithRisky,
            relative_report_path($junitFilename),
            relative_report_path($logFilename),
            $excerpt,
        ));
    }

    echo sprintf(
        '%s: ok (%s); junit=%s; log=%s',
        $stepName,
        $summaryWithRisky,
        relative_report_path($junitFilename),
        relative_report_path($logFilename),
    ).\PHP_EOL;
}

function phpunit_failure_excerpt(string $junitPath, string $logFilename): string
{
    $sections = [];

    $junitFailures = phpunit_junit_failure_excerpt($junitPath);
    if ('' !== $junitFailures) {
        $sections[] = $junitFailures;
    }

    $runtimeIssues = phpunit_log_issue_excerpt($logFilename);
    if ('' !== $runtimeIssues) {
        $sections[] = $runtimeIssues;
    }

    if ([] === $sections) {
        return '';
    }

    return "\n\n".implode("\n\n", $sections);
}

function phpunit_junit_failure_excerpt(string $junitPath): string
{
    if (!is_file($junitPath)) {
        return '';
    }

    $xml = @simplexml_load_file($junitPath);
    if (false === $xml) {
        return '';
    }

    $testcases = $xml->xpath('//testcase[failure or error]');
    if (false === $testcases || [] === $testcases) {
        return '';
    }

    $files = [];
    foreach ($testcases as $testcase) {
        if (!$testcase instanceof SimpleXMLElement) {
            continue;
        }

        $attributes = $testcase->attributes();
        $file = isset($attributes['file']) ? project_relative_path((string) $attributes['file']) : 'unknown file';
        $line = isset($attributes['line']) ? ':'.(string) $attributes['line'] : '';
        $class = isset($attributes['class']) ? (string) $attributes['class'] : '';
        $name = isset($attributes['name']) ? (string) $attributes['name'] : 'unknown test';
        $testName = '' !== $class ? $class.'::'.$name : $name;

        $files[$file] ??= [];
        $files[$file][] = sprintf('  %s%s', $testName, $line);

        foreach (['failure', 'error'] as $nodeName) {
            foreach ($testcase->{$nodeName} as $node) {
                if (!$node instanceof SimpleXMLElement) {
                    continue;
                }

                $nodeAttributes = $node->attributes();
                $type = isset($nodeAttributes['type']) ? (string) $nodeAttributes['type'] : $nodeName;
                $message = isset($nodeAttributes['message']) ? trim((string) $nodeAttributes['message']) : '';
                $files[$file][] = sprintf('    [%s] %s%s', $nodeName, $type, '' !== $message ? ': '.$message : '');

                $body = phpunit_compact_text((string) $node);
                if ('' !== $body) {
                    foreach (explode("\n", $body) as $bodyLine) {
                        $files[$file][] = '      '.$bodyLine;
                    }
                }
            }
        }
    }

    if ([] === $files) {
        return '';
    }

    $lines = [sprintf('--- phpunit failures/errors by file (full report %s) ---', relative_report_path('phpunit.junit.xml'))];
    foreach ($files as $file => $fileLines) {
        $lines[] = $file;
        array_push($lines, ...$fileLines);
    }

    return implode("\n", $lines);
}

function phpunit_log_issue_excerpt(string $logFilename): string
{
    $path = report_path($logFilename);
    if (!is_file($path)) {
        return '';
    }

    $contents = trim((string) file_get_contents($path));
    if ('' === $contents) {
        return '';
    }

    $lines = preg_split('/\R/', $contents);
    if (false === $lines) {
        return '';
    }

    $issues = [];
    $type = null;
    $header = null;
    $body = [];

    foreach ($lines as $line) {
        if (1 === preg_match('/^\d+\s+tests?\s+triggered\s+\d+\s+(.+):$/i', $line, $matches)) {
            $issues = phpunit_append_log_issue($issues, $type, $header, $body);
            $type = strtolower(trim($matches[1]));
            $header = null;
            $body = [];

            continue;
        }

        if (null !== $type && 1 === preg_match('/^\d+\)\s+(.+)$/', $line, $matches)) {
            $issues = phpunit_append_log_issue($issues, $type, $header, $body);
            $header = trim($matches[1]);
            $body = [];

            continue;
        }

        if (null !== $header && 1 === preg_match('/^(OK,|FAILURES!|ERRORS!|Tests:)/', $line)) {
            $issues = phpunit_append_log_issue($issues, $type, $header, $body);
            $type = null;
            $header = null;
            $body = [];

            continue;
        }

        if (null !== $header) {
            $body[] = $line;
        }
    }

    $issues = phpunit_append_log_issue($issues, $type, $header, $body);

    if ([] === $issues) {
        return '';
    }

    $lines = [sprintf('--- phpunit notices/warnings/deprecations by file (full log %s) ---', relative_report_path($logFilename))];
    foreach ($issues as $file => $issueLines) {
        $lines[] = $file;
        array_push($lines, ...$issueLines);
    }

    return implode("\n", $lines);
}

/**
 * @param array<string, list<string>> $issues
 * @param list<string>                $body
 *
 * @return array<string, list<string>>
 */
function phpunit_append_log_issue(array $issues, ?string $type, ?string $header, array $body): array
{
    if (null === $type || null === $header) {
        return $issues;
    }

    $bodyText = phpunit_compact_text(implode("\n", $body));
    $issueFile = 'unknown file';
    $issueLine = '';
    $messageLines = [];

    foreach (explode("\n", $bodyText) as $bodyLine) {
        if (1 === preg_match('/^(?<file>\/?\S+?\.php):(?<line>\d+)$/', $bodyLine, $matches)) {
            $issueFile = project_relative_path($matches['file']);
            $issueLine = ':'.$matches['line'];

            continue;
        }

        if ('' !== trim($bodyLine)) {
            $messageLines[] = $bodyLine;
        }
    }

    $issues[$issueFile] ??= [];
    $issues[$issueFile][] = sprintf('  %s%s [%s]', $header, $issueLine, $type);
    foreach ($messageLines as $messageLine) {
        $issues[$issueFile][] = '    '.$messageLine;
    }

    return $issues;
}

function phpunit_compact_text(string $text): string
{
    $text = str_replace("\r\n", "\n", trim($text));
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    $root = realpath(__DIR__.'/..');
    if (!is_string($root)) {
        return $text;
    }

    return str_replace($root.'/', '', $text);
}

function phpstan_failure_excerpt(string $jsonOutput): string
{
    $decoded = json_decode($jsonOutput, true);
    if (!is_array($decoded) || !is_array($decoded['files'] ?? null)) {
        return '';
    }

    $totalErrors = is_array($decoded['totals'] ?? null) && is_int($decoded['totals']['file_errors'] ?? null)
        ? $decoded['totals']['file_errors']
        : 0;
    $fileCount = count($decoded['files']);

    $identifierCounts = [];
    $fileSections = [];

    foreach ($decoded['files'] as $file => $fileReport) {
        if (!is_array($fileReport) || !is_array($fileReport['messages'] ?? null)) {
            continue;
        }

        $messages = $fileReport['messages'];
        $fileErrors = is_int($fileReport['errors'] ?? null) ? $fileReport['errors'] : count($messages);
        $section = [sprintf('%s (%d)', project_relative_path((string) $file), $fileErrors)];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $identifier = isset($message['identifier']) && is_string($message['identifier']) ? $message['identifier'] : 'unknown';
            $identifierCounts[$identifier] = ($identifierCounts[$identifier] ?? 0) + 1;

            $line = isset($message['line']) && is_int($message['line']) ? 'L'.$message['line'] : 'L?';
            $text = isset($message['message']) && is_string($message['message']) ? $message['message'] : 'unknown PHPStan error';
            $section[] = sprintf('  %s [%s] %s', $line, $identifier, $text);
        }

        $fileSections[] = implode("\n", $section);
    }

    if ([] === $fileSections) {
        return '';
    }

    arsort($identifierCounts);
    $identifierSummary = [];
    foreach ($identifierCounts as $identifier => $count) {
        $identifierSummary[] = $identifier.'='.$count;
    }

    return "\n\n".implode("\n", [
        sprintf('--- phpstan errors by file (%d errors in %d files; full report %s) ---', $totalErrors, $fileCount, relative_report_path('phpstan.json')),
        'by identifier: '.implode(', ', $identifierSummary),
        implode("\n", $fileSections),
    ]);
}

function project_relative_path(string $file): string
{
    $root = realpath(__DIR__.'/..');
    if (is_string($root) && str_starts_with($file, $root.'/')) {
        return substr($file, strlen($root) + 1);
    }

    return $file;
}

// ─── Datadog tasks ────────────────────────────────────────────────

#[AsTask(name: 'datadog:status', description: 'Show local Datadog readiness for Hatfield logs/APM')]
function datadog_status(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $todayLog = $root.'/.hatfield/logs/agent-'.date('Y-m-d').'.log';
    $apmSocket = '/var/run/datadog/apm.socket';
    $installedConfig = '/etc/datadog-agent/conf.d/hatfield.d/conf.yaml';
    $legacyConfig = '/etc/datadog-agent/conf.d/conf.yaml';

    echo 'Datadog Agent: '.trim(shell_exec('datadog-agent version 2>/dev/null') ?? 'not found').\PHP_EOL;
    echo 'Agent process: '.(str_contains(shell_exec("ps -eo user,cmd | grep '[d]atadog-agent/bin/agent/agent run' 2>/dev/null") ?? '', 'datadog-agent') ? 'running' : 'not detected').\PHP_EOL;
    echo 'Trace agent: '.(str_contains(shell_exec("ps -eo user,cmd | grep '[d]atadog-agent/embedded/bin/trace-agent' 2>/dev/null") ?? '', 'trace-agent') ? 'running' : 'not detected').\PHP_EOL;
    echo 'APM socket: '.(datadog_is_unix_socket($apmSocket) ? $apmSocket.' present' : $apmSocket.' missing').\PHP_EOL;
    echo 'PHP ddtrace: '.(extension_loaded('ddtrace') ? 'loaded' : 'not loaded').\PHP_EOL;
    echo 'Default run:agent APM: '.(datadog_auto_enabled() ? 'enabled' : 'disabled').\PHP_EOL;

    if (extension_loaded('ddtrace')) {
        echo 'ddtrace cli enabled: '.(false !== ($_v = ini_get('datadog.trace.cli_enabled')) ? $_v : '(default)').\PHP_EOL;
        echo 'ddtrace enabled: '.(false !== ($_v = ini_get('datadog.trace.enabled')) ? $_v : '(default)').\PHP_EOL;
        echo 'ddtrace service: '.(false !== ($_v = ini_get('datadog.service')) ? $_v : '(unset)').\PHP_EOL;
        echo 'ddtrace env: '.(false !== ($_v = ini_get('datadog.env')) ? $_v : '(unset)').\PHP_EOL;
        echo 'ddtrace agent_url: '.(false !== ($_v = ini_get('datadog.trace.agent_url')) ? $_v : '(default)').\PHP_EOL;
    }

    echo 'Hatfield log today: '.$todayLog.' '.(is_readable($todayLog) ? 'readable' : 'missing/not-readable').\PHP_EOL;
    echo 'Expected Agent config: '.$installedConfig.' '.(is_readable($installedConfig) ? 'present' : 'missing/not-readable').\PHP_EOL;
    if (is_readable($legacyConfig)) {
        echo 'Legacy config warning: '.$legacyConfig.' exists; prefer conf.d/hatfield.d/conf.yaml'.\PHP_EOL;
    }

    echo \PHP_EOL.'Install/check commands:'.\PHP_EOL;
    echo '  castor datadog:log-config'.\PHP_EOL;
    echo '  sudo systemctl restart datadog-agent'.\PHP_EOL;
    echo '  castor datadog:smoke-log'.\PHP_EOL;
}

#[AsTask(name: 'datadog:log-config', description: 'Print the Datadog Agent Hatfield log config and install hints')]
function datadog_log_config(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $config = $root.'/ops/datadog/hatfield.d/conf.yaml';

    echo file_get_contents($config);
    echo \PHP_EOL.'Install with:'.\PHP_EOL;
    echo '  sudo mkdir -p /etc/datadog-agent/conf.d/hatfield.d'.\PHP_EOL;
    echo '  sudo install -o dd-agent -g dd-agent -m 0644 ops/datadog/hatfield.d/conf.yaml /etc/datadog-agent/conf.d/hatfield.d/conf.yaml'.\PHP_EOL;
    echo '  sudo rm -f /etc/datadog-agent/conf.d/conf.yaml'.\PHP_EOL;
    echo '  setfacl -m u:dd-agent:--x /home/ineersa'.\PHP_EOL;
    echo '  setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core/.hatfield/logs'.\PHP_EOL;
    echo '  setfacl -m u:dd-agent:rX /home/ineersa/projects/agent-core-worktrees 2>/dev/null || true'.\PHP_EOL;
    echo '  sudo systemctl restart datadog-agent'.\PHP_EOL;
}

#[AsTask(name: 'datadog:smoke-log', description: 'Write a Datadog log collection smoke-test line')]
function datadog_smoke_log(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $logDir = $root.'/.hatfield/logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        throw new RuntimeException(sprintf('Unable to create log directory "%s".', $logDir));
    }

    $message = 'datadog smoke '.date(\DATE_ATOM).' '.bin2hex(random_bytes(4));
    $line = json_encode([
        'message' => $message,
        'context' => ['component' => 'datadog:smoke-log'],
        'level' => 200,
        'level_name' => 'INFO',
        'channel' => 'app',
        'datetime' => date(\DATE_ATOM),
        'extra' => ['service' => 'hatfield', 'env' => 'dev'],
    ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

    $path = $logDir.'/agent-'.date('Y-m-d').'.log';
    file_put_contents($path, $line.\PHP_EOL, \FILE_APPEND | \LOCK_EX);

    echo 'Wrote smoke log line to '.project_relative_path($path).\PHP_EOL;
    echo 'Search Datadog Logs Explorer for: "'.$message.'"'.\PHP_EOL;
}

// ─── Log tasks ────────────────────────────────────────────────────
//
// Thin wrappers that delegate to Symfony console commands.
// Parameter signatures mirror the command options/arguments so Castor
// validates them. Values are forwarded directly to bin/console.
// The app container resolves logging.path from Hatfield config —
// Castor never resolves config or instantiates app services.

#[AsTask(name: 'log:tail', description: 'Show recent log entries (→ bin/console log:tail)')]
function log_tail(?string $level = null, int $lines = 50, ?string $search = null): void
{
    $cmd = escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:tail';
    if (null !== $level) {
        $cmd .= ' --level='.escapeshellarg($level);
    }
    $cmd .= ' --lines='.$lines;
    if (null !== $search) {
        $cmd .= ' --search='.escapeshellarg($search);
    }
    $cmd .= ' --format='.(is_llm_mode() ? 'toon' : 'jsonl');
    passthru($cmd, $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'log:search', description: 'Search log entries across all log files (→ bin/console log:search)')]
function log_search(string $query, ?string $level = null, ?string $from = null, ?string $to = null): void
{
    $cmd = escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:search '.escapeshellarg($query);
    if (null !== $level) {
        $cmd .= ' --level='.escapeshellarg($level);
    }
    if (null !== $from) {
        $cmd .= ' --from='.escapeshellarg($from);
    }
    if (null !== $to) {
        $cmd .= ' --to='.escapeshellarg($to);
    }
    $cmd .= ' --format='.(is_llm_mode() ? 'toon' : 'jsonl');
    passthru($cmd, $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'log:files', description: 'List log files with size and modification date (→ bin/console log:files)')]
function log_files(): void
{
    $cmd = escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:files';
    $cmd .= ' --format='.(is_llm_mode() ? 'toon' : 'jsonl');
    passthru($cmd, $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'log:clear', description: 'Remove old rotated log files (→ bin/console log:clear)')]
function log_clear(string $olderThan = '7 days ago'): void
{
    passthru(escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:clear --older-than='.escapeshellarg($olderThan), $exitCode);
    exit($exitCode);
}

/**
 * Recursively remove a directory tree.  Used by the cleanup task
 * to remove generated temp/test artifacts (not a Castor task itself).
 */
function rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir((string) $entry) : unlink((string) $entry);
    }

    rmdir($dir);
}

// ── Shared proc_open parallel runner ──────────────────────────

/**
 * Run multiple shell commands concurrently via proc_open.
 *
 * Each command is spawned as an independent PHP subprocess — no
 * shared memory, safe inside the Castor PHAR.  stdout and stderr
 * are captured to the per-command log file.
 *
 * @param array<string,array{cmd:string,log:string}> $commands
 *
 * @return array<string,array{exitCode:int,output:string,duration:float}>
 */
function run_commands_parallel(array $commands, ?float $timeout = null): array
{
    $processes = [];
    $results = [];
    $cwd = (string) getcwd();

    // Start every process immediately.
    foreach ($commands as $step => $info) {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open($info['cmd'], $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            $results[$step] = [
                'exitCode' => -1,
                'output' => 'proc_open() failed for: '.$info['cmd'],
                'duration' => 0,
            ];
            continue;
        }

        fclose($pipes[0]); // close stdin immediately

        $processes[$step] = [
            'handle' => $process,
            'pipes' => [$pipes[1], $pipes[2]],
            'start' => hrtime(true),
            'log' => $info['log'] ?? null,
            'timedOut' => false,
        ];
    }

    // Poll until every process exits.
    while (count($processes) > 0) {
        $finished = [];
        foreach ($processes as $step => $pInfo) {
            $status = proc_get_status($pInfo['handle']);
            if (!$status['running']) {
                $finished[] = $step;
            } elseif (null !== $timeout) {
                $elapsed = (hrtime(true) - $pInfo['start']) / 1e9;
                if ($elapsed > $timeout) {
                    proc_terminate($pInfo['handle']);
                    usleep(100000); // brief grace for SIGTERM
                    @proc_terminate($pInfo['handle'], 9); // SIGKILL
                    $processes[$step]['timedOut'] = true;
                    $finished[] = $step;
                }
            }
        }

        foreach ($finished as $step) {
            $pInfo = $processes[$step];
            $stdout = stream_get_contents($pInfo['pipes'][0]);
            $stderr = stream_get_contents($pInfo['pipes'][1]);
            fclose($pInfo['pipes'][0]);
            fclose($pInfo['pipes'][1]);
            $exitCode = proc_close($pInfo['handle']);
            $duration = (hrtime(true) - $pInfo['start']) / 1e9;

            $output = (string) $stdout;
            if ('' !== (string) $stderr) {
                $output = '' !== $output ? $output.'
'.$stderr : $stderr;
            }

            if ($pInfo['timedOut']) {
                $output = "TIMEOUT after {$timeout}s\n".$output;
                if (0 === $exitCode) {
                    $exitCode = -1;
                }
            }

            if (null !== $pInfo['log']) {
                @mkdir(dirname($pInfo['log']), 0755, true);
                file_put_contents($pInfo['log'], $output."\n");
            }

            $results[$step] = [
                'exitCode' => $exitCode,
                'output' => $output,
                'duration' => $duration,
            ];

            unset($processes[$step]);
        }

        if (count($processes) > 0) {
            usleep(50000); // 50 ms
        }
    }

    return $results;
}

/**
 * Run check steps in parallel via proc_open subprocesses.
 *
 * Each step's stdout+stderr is captured to
 * var/reports/check-<step>.log.  The parent waits for all
 * processes, then prints a combined timing summary.
 *
 * @param array<string,array{cmd:string}> $steps
 * @param array<string,string>            $failures out
 * @param array<string,float|int>         $timings  out
 */
function run_check_commands_parallel(array $steps, array &$failures, array &$timings): void
{
    $logFiles = [];
    $commands = [];
    foreach ($steps as $step => $info) {
        $logFiles[$step] = report_path("check-{$step}.log");
        $commands[$step] = [
            'cmd' => $info['cmd'],
            'log' => $logFiles[$step],
        ];
    }
    @mkdir(\CastorTasks\REPORTS_DIR, 0755, true);

    echo 'Running steps in parallel (proc_open):
';
    foreach (array_keys($steps) as $step) {
        echo "  - {$step}
";
    }
    echo '
';

    $results = run_commands_parallel($commands, timeout: 300);

    foreach ($steps as $step => $_) {
        $result = $results[$step] ?? ['exitCode' => -1, 'output' => 'proc_open failed', 'duration' => 0];
        $timings[$step] = $result['duration'];
        if (0 !== $result['exitCode']) {
            $failures[$step] = $result['exitCode'] < 0
                ? $result['output']
                : 'exit code '.$result['exitCode'];
        }
    }

    echo 'Summary:
';
    foreach ($steps as $step => $_) {
        $status = isset($failures[$step]) ? 'FAIL' : 'OK';
        $duration = $timings[$step] ?? 0;
        echo sprintf('  %-20s %s  (%.1fs)', $step, $status, $duration);

        if (isset($failures[$step])) {
            echo "  — {$failures[$step]}";
        } elseif (is_file($logFiles[$step])) {
            $logContent = file_get_contents($logFiles[$step]);
            if (false !== $logContent) {
                $lines = explode('
', trim($logContent));
                $lastLine = end($lines);
                if ('' !== $lastLine) {
                    echo "  — {$lastLine}";
                }
            }
        }
        echo '
';
    }
}

/**
 * Fallback sequential runner (proc_open not available).
 *
 * Runs every step in order with per-step timing.
 *
 * @param array<string,array{cmd:string}> $steps
 * @param array<string,string>            $failures out
 * @param array<string,float>             $timings  out
 */
function run_check_commands_sequential(array $steps, array &$failures, array &$timings): void
{
    echo 'Running steps sequentially (proc_open not available):

';

    $overallStart = hrtime(true);

    foreach ($steps as $step => $info) {
        echo sprintf('  %-20s ... ', $step);
        $start = hrtime(true);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($info['cmd'], $descriptors, $pipes);

        if (!is_resource($process)) {
            $duration = (hrtime(true) - $start) / 1e9;
            $timings[$step] = $duration;
            $failures[$step] = 'proc_open failed';
            echo sprintf('FAIL (%.1fs): proc_open failed
', $duration);
            continue;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $duration = (hrtime(true) - $start) / 1e9;
        $timings[$step] = $duration;

        if (0 !== $exitCode) {
            $failures[$step] = 'exit code '.$exitCode;
            echo sprintf('FAIL (%.1fs): exit code %d
', $duration, $exitCode);
        } else {
            echo sprintf('ok (%.1fs)
', $duration);
        }
    }

    echo sprintf('
Total: %.1fs
', (hrtime(true) - $overallStart) / 1e9);
}
