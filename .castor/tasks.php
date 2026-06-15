<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\run;
use function CastorTasks\build_idea_run_config_xml;
use function CastorTasks\check_llm_generation_ready;
use function CastorTasks\is_llm_mode;
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
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    // Belt-and-suspenders: kill stale messenger/controller workers from
    // previous castor check runs that may have leaked through per-step
    // timeouts.  Scoped to this checkout only.
    cleanup_stale_check_workers($root);

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
    //
    // The PHPUnit lane is expanded into the same workers used by
    // `castor test` instead of spawning a nested Castor process or a
    // monolithic PHPUnit run.  This keeps check() parallel at the top
    // level while preserving per-worker DB/cache/JUnit isolation.
    $testCheckCommands = [];
    foreach (array_merge(['agent-core'], array_keys(coding_agent_shard_groups()), ['tui', 'platform']) as $worker) {
        $step = match ($worker) {
            'tui' => 'test-tui-suite',
            default => 'test-'.$worker,
        };

        $testCheckCommands[$step] = [
            'cmd' => timeout_check_command(
                build_test_worker_command($worker, $pharEnv, 'app_test-'.$worker.'.sqlite', $step),
                75,
            ),
        ];
    }

    $allCheckCommands = array_merge([
        'deptrac' => [
            'cmd' => timeout_check_command(
                $phpBin.' vendor/bin/deptrac --config-file=depfile.yaml --no-progress --no-ansi'
                    .(is_llm_mode() ? ' --formatter=json' : ''),
                30,
            ),
        ],
    ], $testCheckCommands, [
        'test:controller' => [
            'cmd' => timeout_check_command(
                'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.$phpBin.' vendor/bin/phpunit'
                    .' --filter=ControllerSmokeTest'
                    .' '.$strictFlags.$llmFlags
                    .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-controller.junit.xml') : ''),
                30,
            ),
        ],
        'test:llm-real' => [
            'cmd' => timeout_check_command(
                'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.$phpBin.' vendor/bin/phpunit'
                    .' --group llm-real'
                    .' '.$strictFlags.$llmFlags
                    .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-llm-real.junit.xml') : ''),
                120,
            ),
        ],
        'test:tui-1' => [
            'cmd' => timeout_check_command(
                build_tui_e2e_worker_command(1, $pharEnv),
                75,
            ),
        ],
        'test:tui-2' => [
            'cmd' => timeout_check_command(
                build_tui_e2e_worker_command(2, $pharEnv),
                75,
            ),
        ],
        'phpstan' => [
            'cmd' => timeout_check_command(
                $phpBin.' vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress'
                    .(is_llm_mode() ? ' --error-format=json --no-ansi' : ''),
                30,
            ),
        ],
        'cs-check' => [
            'cmd' => timeout_check_command(
                $phpBin.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --no-ansi'
                    .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff'),
                30,
            ),
        ],
    ]);

    // DB schema must be ready before the test / controller / llm-real
    // steps start.  Migrate once (fast, idempotent).
    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    // Fail-fast: verify llama.cpp can actually generate before burning
    // time on controller / llm-real / TUI E2E steps.  Health-only checks
    // are insufficient — the server can respond to /health and /v1/models
    // while generation is stuck (corrupted model load, slots busy).
    check_llm_generation_ready();

    $failures = [];
    $timings = [];

    $GLOBALS['CASTOR_CHECK_AGGREGATING'] = true;
    try {
        $useParallel = \PHP_SAPI === 'cli' && function_exists('proc_open');
        if ($useParallel) {
            run_check_commands_parallel($allCheckCommands, $failures, $timings);
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

    echo sprintf('

quality: ok (%.1fs)
', array_sum($timings));
}

// ─── PHAR packaging ─────────────────────────────────────────────────
// Most PHAR-related logic lives in helpers.php so it can be shared
// between castor tasks and the PHAR build script.

#[AsTask(name: 'phar:build', namespace: 'phar', description: 'Build hatfield.phar')]
function phar_build(): void
{
    // Only build the project-local PHAR needed for controller/TUI subprocess
    // tests; the real CI artifact lives in tools/phar/build-phar.php and only
    // needs to be rebuilt when the source packaging script changes.
    passthru(\PHP_BINARY.' '.__DIR__.'/../bin/console phar:build', $exitCode);
    if (0 !== $exitCode) {
        throw new RuntimeException("phar:build console command exited with code {$exitCode}");
    }
}

#[AsTask(name: 'phar:ensure', namespace: 'phar', description: 'Ensure hatfield.phar exists (build if missing or stale)')]
function phar_ensure(): void
{
    try {
        \CastorTasks\phar_ensure();
    } catch (Throwable $e) {
        echo "phar:ensure error: {$e->getMessage()}
";
    }
}

#[AsTask(name: 'phar:clean', namespace: 'phar', description: 'Remove worktree-local hatfield.phar')]
function phar_clean(): void
{
    $path = \CastorTasks\hatfield_phar_path();
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException("Failed to remove {$path}");
    }
    echo "Removed {$path}
";
}

#[AsTask(name: 'phar:info', namespace: 'phar', description: 'Display PHAR path and build timestamp')]
function phar_info(): void
{
    $path = \CastorTasks\hatfield_phar_path();
    echo 'PHAR path: '.$path.\PHP_EOL;
    echo 'Exists: '.(is_file($path) ? 'yes' : 'no').\PHP_EOL;
    if (is_file($path)) {
        echo 'Size: '.filesize($path).' bytes'.\PHP_EOL;
        echo 'Modified: '.date(\DATE_ATOM, filemtime($path)).\PHP_EOL;
    }
}

// ─── Cleanup ──────────────────────────────────────────────────────

#[AsTask(name: 'cleanup', namespace: 'clean', description: 'Remove all temp/test artifacts')]
function cleanup(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    $dirsToRemove = [
        $root.'/var/tmp/tui-e2e-',
        $root.'/var/tmp/tui-failures',
        $root.'/var/tmp/test-',
        $root.'/var/tmp/phar-build',
    ];

    $toRemove = [];
    foreach ($dirsToRemove as $prefix) {
        // Strip trailing separator if present; glob for prefix*
        $base = rtrim($prefix, \DIRECTORY_SEPARATOR);
        $parent = dirname($base);
        if (!is_dir($parent)) {
            continue;
        }
        $pattern = $base.'*';
        $entries = glob($pattern);
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if (is_dir($entry)) {
                    $toRemove[] = $entry;
                }
            }
        }
    }

    $noop = true;
    foreach ($toRemove as $dir) {
        rmtree($dir);
        echo 'Removed '.project_relative_path($dir).\PHP_EOL;
        $noop = false;
    }

    if ($noop) {
        echo 'Nothing to clean up.
';
    }
}

// ─── Testing ──────────────────────────────────────────────────────

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
        $pharPath = \CastorTasks\phar_ensure();
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
                75,
            ),
        ];
    }

    return $commands;
}

#[AsTask(name: 'test:llm-real', description: 'Run real LLM smoke tests')]
function test_llm_real(?string $filter = null): void
{
    $filterArg = null !== $filter ? ' --filter='.escapeshellarg($filter) : '';
    if ('' === $filterArg) {
        // Explicit filter is mandatory for test:llm-real (run full group).
        $filterArg = ' --group llm-real';
    }
    check_llm_generation_ready();

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
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $cmd = 'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.\PHP_BINARY.' vendor/bin/phpunit'
        .$filterArg
        .' '.phpunit_strict_issue_flags()
        .(is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-llm-real.junit.xml') : '');

    $start = hrtime(true);
    passthru($cmd, $exitCode);
    $duration = (hrtime(true) - $start) / 1e9;

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('llm-real');
        if ('' !== $summary) {
            echo "{$summary}
";
        }
    }

    if (0 !== $exitCode) {
        fail_quality(sprintf('LLM real smoke tests failed in %.1fs (exit code %d)', $duration, $exitCode));
    }
    echo sprintf('

OK (%.1fs)
', $duration);
    exit(0);
}

#[AsTask(name: 'test:tui', description: 'Run TUI E2E smoke tests')]
function test_tui(?string $filter = null): void
{
    $filterArg = null !== $filter ? ' --filter='.escapeshellarg($filter) : '';
    check_tmux();
    check_llm_generation_ready();

    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

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
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    // DB must be ready before E2E tests start.
    @mkdir('var/test', 0755, true);
    $migrate2 = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate2->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate2->getErrorOutput());
    }

    $testFiles = [];
    if ('' !== $filterArg) {
        $testFiles[] = 'tests/Tui/E2E';
    } else {
        // Run ALL E2E test files as a single PHPUnit invocation so the
        // shared isolated DB, PHAR, and tmux overhead is paid once per
        // batch, not per-test.  Shards are only relevant inside check().
        $testFiles[] = 'tests/Tui/E2E';
    }

    $phpunitArgs = implode(' ', array_map('escapeshellarg', $testFiles));

    $cmd = 'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.\PHP_BINARY.' vendor/bin/phpunit'
        .' '.$phpunitArgs
        .' '.phpunit_strict_issue_flags()
        .(is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-tui.junit.xml') : '');

    $start = hrtime(true);
    passthru($cmd, $exitCode);
    $duration = (hrtime(true) - $start) / 1e9;

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('tui');
        if ('' !== $summary) {
            echo "{$summary}
";
        }
    }

    if (0 !== $exitCode) {
        fail_quality(sprintf('TUI E2E tests failed in %.1fs (exit code %d)', $duration, $exitCode));
    }
    echo sprintf('

OK (%.1fs)
', $duration);
    exit(0);
}

#[AsTask(name: 'test:tui-update', description: 'Update TUI E2E snapshot baselines')]
function test_tui_update(): void
{
    check_tmux();
    check_llm_generation_ready();

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
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    echo 'Running TUI E2E tests with snapshot update...
';
    passthru(
        'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.\PHP_BINARY.' vendor/bin/phpunit'
        .' tests/Tui/E2E '
        .' --colors=never --no-progress --do-not-cache-result --log-junit='.report_path('phpunit-tui-update.junit.xml'),
        $exitCode,
    );

    echo sprintf('

TUI snapshot update complete (exit code %d).
', $exitCode);
}

#[AsTask(name: 'test:controller', description: 'Run controller E2E smoke tests')]
function test_controller(): void
{
    check_llm_generation_ready();

    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

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
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-controller.junit.xml') : '';

    $cmd = 'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.\PHP_BINARY.' vendor/bin/phpunit'
        .' --filter=ControllerSmokeTest'
        .' '.$strictFlags.$llmFlags;

    $start = hrtime(true);
    passthru($cmd, $exitCode);
    $duration = (hrtime(true) - $start) / 1e9;

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('controller');
        if ('' !== $summary) {
            echo "{$summary}
";
        }
    }

    if (0 !== $exitCode) {
        fail_quality(sprintf('Controller E2E tests failed in %.1fs (exit code %d)', $duration, $exitCode));
    }
    echo sprintf('

OK (%.1fs)
', $duration);
    exit(0);
}

#[AsTask(name: 'run:agent', description: 'Run the agent in a tmux window')]
function run_agent(): void
{
    check_tmux();
    $session = 'hatfield-agent-'.getmypid();
    $cwd = getcwd();
    $cmd = 'cd '.escapeshellarg($cwd).' && '.\PHP_BINARY.' bin/console agent 2>&1';
    passthru("tmux new-session -s {$session} {$cmd}", $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'run:agent-test', description: 'Run the agent in a tmux window using the local test model')]
function run_agent_test(): void
{
    check_tmux();
    $session = 'hatfield-agent-test-'.getmypid();
    $cwd = getcwd();
    $cmd = 'cd '.escapeshellarg($cwd).' && '.\PHP_BINARY.' bin/console agent --model=llama_cpp_test/test 2>&1';
    passthru("tmux new-session -s {$session} {$cmd}", $exitCode);
    exit($exitCode);
}

// ─── Static analysis ──────────────────────────────────────────────

#[AsTask(name: 'deptrac', description: 'Run Deptrac architecture validation')]
function deptrac(): void
{
    $cmd = \PHP_BINARY.' vendor/bin/deptrac --config-file=depfile.yaml --no-progress --no-ansi'
        .(is_llm_mode() ? ' --formatter=json' : '');
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_deptrac_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('Deptrac failed with exit code %d', $exitCode));
    }
    exit(0);
}

#[AsTask(name: 'phpstan', description: 'Run PHPStan static analysis')]
function phpstan(?string $path = null): void
{
    $cmd = \PHP_BINARY.' vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress'
        .(is_llm_mode() ? ' --error-format=json --no-ansi' : '');
    if (null !== $path) {
        $cmd .= ' '.$path;
    }
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_phpstan_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('PHPStan failed with exit code %d', $exitCode));
    }
}

#[AsTask(name: 'phpstan:baseline', description: 'Regenerate PHPStan baseline')]
function phpstan_baseline(): void
{
    passthru(\PHP_BINARY.' vendor/bin/phpstan analyse -c phpstan.dist.neon --generate-baseline phpstan-baseline.neon', $exitCode);
    if (0 !== $exitCode) {
        fail_quality(sprintf('PHPStan baseline generation failed with exit code %d', $exitCode));
    }
}

// ─── Coding style ─────────────────────────────────────────────────

#[AsTask(name: 'cs-fix', description: 'Fix coding style')]
function cs_fix(string $path = ''): void
{
    $cmd = \PHP_BINARY.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --no-ansi'
        .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff');
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }
    passthru($cmd, $exitCode);
    if (0 !== $exitCode) {
        fail_quality(sprintf('CS fixer failed with exit code %d', $exitCode));
    }
}

#[AsTask(name: 'cs-check', description: 'Check coding style (dry-run)')]
function cs_check(string $path = ''): void
{
    $cmd = \PHP_BINARY.' vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --no-ansi'
        .(is_llm_mode() ? ' --format=json --show-progress=none' : ' --diff');
    if ('' !== $path) {
        $cmd .= ' '.escapeshellarg($path);
    }
    $exitCode = 0;
    $output = [];
    exec($cmd, $output, $exitCode);
    $output = implode("\n", $output);
    echo $output.\PHP_EOL;
    if (is_llm_mode()) {
        $summary = summarize_php_cs_fixer_json($output);
        if ('' !== $summary) {
            echo $summary;
        }
    }
    if (0 !== $exitCode) {
        fail_quality(sprintf('CS check failed with exit code %d', $exitCode));
    }
}

// ─── Audit ────────────────────────────────────────────────────────

#[AsTask(name: 'audit', description: 'Run Composer security audit')]
function audit(): void
{
    $cmd = \PHP_BINARY.' '.__DIR__.'/../vendor/bin/security-checker security:check '.__DIR__.'/../composer.lock';
    passthru($cmd, $exitCode);
    exit($exitCode);
}

// ─── IDE helpers ──────────────────────────────────────────────────

#[AsTask(name: 'ide:config', description: 'Generate IDE run configuration XML')]
function ide_config(): void
{
    echo build_idea_run_config_xml();
}

// ─── Misc helpers ─────────────────────────────────────────────────

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
function cs_fixer(): void
{
    cs_fix();
}

/**
 * Run static analysis (PHPStan + Deptrac).
 */
#[AsTask(name: 'analyse', description: 'Run static analysis (PHPStan + Deptrac)')]
function analyse(): void
{
    phpstan();
    deptrac();
}

/**
 * PHPUnit strict issue flags shared across all test tasks.
 */
function phpunit_strict_issue_flags(): string
{
    return '--stop-on-error --stop-on-failure --fail-on-all-issues --display-all-issues';
}

/**
 * Return a project-root-relative path for absolute paths under the
 * project root; return the input unchanged otherwise.
 */
function project_relative_path(string $absolute): string
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    if (str_starts_with($absolute, $root)) {
        return '.'.substr($absolute, strlen($root));
    }

    return $absolute;
}

/**
 * Assert tmux is installed.
 *
 * Several Castor tasks (test:tui, test:tui-update, run:agent,
 * run:agent-test) require tmux for TUI E2E snapshots or interactive
 * agent sessions.  Call this at the top of those tasks to fail early
 * with a clear diagnostic instead of a cryptic proc_open error.
 */
function check_tmux(): void
{
    $which = trim(shell_exec('which tmux 2>/dev/null') ?? '');
    if ('' === $which) {
        throw new RuntimeException('tmux is not installed. Install it with your package manager before using run:* tasks.');
    }
}

/**
 * Show Hatfield settings sourcing, basic app info, and env vars.
 */
#[AsTask(name: 'diagnostic', namespace: 'diag', description: 'Show Hatfield settings sourcing, basic app info, and env vars')]
function diagnostic(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    echo 'PHP: '.\PHP_VERSION.' '.\PHP_BINARY.\PHP_EOL;
    echo 'SAPI: '.\PHP_SAPI.\PHP_EOL;
    echo 'OS: '.\PHP_OS.\PHP_EOL;
    echo 'CWD: '.(string) getcwd().\PHP_EOL;
    echo 'Root: '.$root.\PHP_EOL;
    echo \PHP_EOL;

    echo "Hatfield settings files (built-in defaults → ~/.hatfield/ → project):\n";
    echo '  1. config/hatfield.defaults.yaml '.(is_readable($root.'/config/hatfield.defaults.yaml') ? 'present' : 'missing').\PHP_EOL;
    echo '  2. ~/.hatfield/settings.yaml       '.(is_readable($_SERVER['HOME'].'/.hatfield/settings.yaml') ? 'present' : 'missing').\PHP_EOL;
    echo '  3.  ./.hatfield/settings.yaml       '.(is_readable($root.'/.hatfield/settings.yaml') ? 'present' : 'missing').\PHP_EOL;
    echo \PHP_EOL;

    echo "Environment:\n";
    $vars = ['APP_ENV', 'APP_DEBUG', 'DATABASE_URL', 'HATFIELD_CWD'];
    foreach ($vars as $var) {
        $val = $_SERVER[$var] ?? $_ENV[$var] ?? null;
        if (null !== $val && str_contains($var, 'DATABASE')) {
            // Redact DB credentials in the path portion.
            $val = preg_replace('{://[^@]+@}', '://REDACTED@', $val);
        }
        echo '  '.$var.'='.($val ?? '(unset)').\PHP_EOL;
    }
    echo \PHP_EOL;

    echo "Symfony debug:\n";
    echo '  env: '.App\Kernel::env().\PHP_EOL;
    $paths = App\Kernel::hatfieldConfigPaths();
    echo '  config paths: '.implode(' : ', $paths).\PHP_EOL;

    try {
        App\Kernel::boot();
    } catch (Throwable $e) {
        echo '  boot error: '.$e->getMessage().\PHP_EOL;
    }

    echo \PHP_EOL;

    $homeDir = $_SERVER['HOME'];
    echo "Home: {$homeDir}\n";
    $globalDir = App\Kernel::resolveGlobalHatfieldDir();
    echo 'Global dir: '.($globalDir ?? '(null)').\PHP_EOL;
    if (null !== $globalDir) {
        echo 'Global dir readable: '.(is_readable($globalDir) ? 'yes' : 'no').\PHP_EOL;
    }
    echo \PHP_EOL;
}

#[AsTask(name: 'diagnostic:full', namespace: 'diag', description: 'Show full Hatfield diagnostics')]
function diagnostic_full(): void
{
    diagnostic();
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');

    echo "Hatfield tree:\n";
    try {
        App\Kernel::boot();
    } catch (Throwable $e) {
        echo '  boot error: '.$e->getMessage().\PHP_EOL;
    }
}

// ─── Datadog tasks ──────────────────────────────────────────────

#[AsTask(name: 'datadog:smoke', namespace: 'datadog', description: 'Show Datadog smoke diagnostic')]
function datadog_smoke_diag(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $today = date('Y-m-d');
    $todayLog = "{$root}/.hatfield/logs/agent-{$today}.log";
    $installedConfig = '/etc/datadog-agent/conf.d/hatfield.d/conf.yaml';
    $legacyConfig = '/etc/datadog-agent/conf.d/conf.yaml';

    echo 'Datadog smoke diagnostic'.\PHP_EOL;
    echo \PHP_EOL;

    echo 'Package: '.(false !== ($_v = shell_exec('dpkg -l datadog-agent 2>/dev/null | grep ^ii')) ? trim($_v) : 'not installed').\PHP_EOL;
    echo 'Datadog agent: '.(false !== ($_v = shell_exec('systemctl is-active datadog-agent 2>/dev/null')) ? trim($_v) : 'unknown').\PHP_EOL;
    echo \PHP_EOL;

    echo "PHP extension:\n";
    echo '  ddtrace: '.(extension_loaded('ddtrace') ? 'yes' : 'no').\PHP_EOL;
    if (extension_loaded('ddtrace')) {
        echo '  ddtrace cli enabled: '.(false !== ($_v = ini_get('datadog.trace.cli_enabled')) ? $_v : '(default)').\PHP_EOL;
        echo '  ddtrace enabled: '.(false !== ($_v = ini_get('datadog.trace.enabled')) ? $_v : '(default)').\PHP_EOL;
        echo '  ddtrace service: '.(false !== ($_v = ini_get('datadog.service')) ? $_v : '(unset)').\PHP_EOL;
        echo '  ddtrace env: '.(false !== ($_v = ini_get('datadog.env')) ? $_v : '(unset)').\PHP_EOL;
        echo '  ddtrace agent_url: '.(false !== ($_v = ini_get('datadog.trace.agent_url')) ? $_v : '(default)').\PHP_EOL;
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
 * Each command is spawned in an isolated process group via setsid -w
 * so Castor can cleanly reap the entire tree — including escaped
 * grandchildren like messenger:consume — after both timeout AND
 * normal completion.
 *
 * Pipe I/O is non-blocking and incremental: output is read during the
 * poll loop so that surviving grandchildren never cause a blocking
 * stream_get_contents hang after the direct child exits.
 *
 * @param array<string,array{cmd:string,log:string}> $commands
 * @param array<string,int>                          $timeouts Optional per-step timeout in seconds.
 *                                                             Defaults to empty (no Castor-enforced timeout).
 *
 * @return array<string,array{exitCode:int,output:string,duration:float}>
 */
function run_commands_parallel(array $commands, array $timeouts = []): array
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

        // Spawn inside an isolated session via setsid -w.
        // setsid -w does NOT fork: it calls setsid() in-process then
        // execs sh -lc <cmd>.  The proc_open child PID is the session
        // leader (SID) and initial process-group leader (PGID).
        //
        // kill(-PGID) alone is insufficient: grandchildren spawned in
        // their own PGIDs (messenger:consume, agent --controller) share
        // the same SID but have different PGIDs.  Session-based cleanup
        // via _reap_session() catches ALL processes in the SID.
        $process = @proc_open(
            ['setsid', '-w', 'sh', '-lc', $info['cmd']],
            $descriptors,
            $pipes,
            $cwd,
        );

        if (!is_resource($process)) {
            $results[$step] = [
                'exitCode' => -1,
                'output' => 'proc_open() failed for: '.$info['cmd'],
                'duration' => 0,
            ];
            continue;
        }

        fclose($pipes[0]); // close stdin immediately

        // Non-blocking so the poll loop never blocks on reads when
        // grandchildren hold the write end of the pipe open after the
        // direct child has exited.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // The proc_open child *is* the session leader, so its PID is
        // both SID and PGID.  We store it as $sid for session-scoped
        // cleanup that catches separate-PGID grandchildren.
        $sid = proc_get_status($process)['pid'];

        $processes[$step] = [
            'handle' => $process,
            'pipes' => [$pipes[1], $pipes[2]],
            'start' => hrtime(true),
            'log' => $info['log'] ?? null,
            'outBuf' => '',
            'errBuf' => '',
            'timedOut' => false,
            'sid' => $sid > 0 ? $sid : null,
        ];
    }

    // Poll until every process exits or Castor hard-timeout fires.
    while (count($processes) > 0) {
        $finished = [];
        $now = hrtime(true);

        foreach ($processes as $step => $pInfo) {
            // ── Non-blocking incremental output capture ──
            $chunk = @fread($pInfo['pipes'][0], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $processes[$step]['outBuf'] .= $chunk;
            }
            $chunk = @fread($pInfo['pipes'][1], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $processes[$step]['errBuf'] .= $chunk;
            }

            $status = proc_get_status($pInfo['handle']);
            $elapsed = ($now - $pInfo['start']) / 1e9;
            $stepTimeout = $timeouts[$step] ?? null;

            if (!$status['running']) {
                $finished[] = $step;
            } elseif (null !== $stepTimeout && $elapsed >= $stepTimeout) {
                $processes[$step]['timedOut'] = true;
                $finished[] = $step;
            }
        }

        foreach ($finished as $step) {
            $pInfo = $processes[$step];
            $elapsed = (hrtime(true) - $pInfo['start']) / 1e9;

            // ── Snapshot descendant tree BEFORE intermediate parents exit ──
            // Once proc_close() returns, intermediate parents are dead and
            // grandchildren are reparented to systemd — pgrep -P can no
            // longer find them.  Collect the full tree first, then reap.
            // Also snapshot session PIDs: session cleanup is the primary
            // mechanism for separate-PGID grandchildren that pgrep -P misses.
            $descendantPids = _collect_descendant_pids($pInfo['sid'] ?? 0);
            $sessionPids = _collect_session_pids($pInfo['sid'] ?? 0);

            // ── Close our pipe ends FIRST ──
            // Close stdout/stderr before reaping so that processes
            // holding the write end get SIGPIPE / exit faster.
            @fclose($pInfo['pipes'][0]);
            @fclose($pInfo['pipes'][1]);

            // ── Reap by SESSION FIRST (primary mechanism) ──
            // This kills ALL processes in the step's session including
            // grandchildren in separate PGIDs (messenger:consume workers,
            // agent --controller children) that kill(-PGID) misses.
            _reap_session($pInfo['sid']);

            if ($pInfo['timedOut']) {
                // ── Kill the full process group (belt-and-suspenders) ──
                _reap_process_group($pInfo['sid']);
                @proc_close($pInfo['handle']);
                $exitCode = 124; // matches GNU timeout convention
                $stepTimeout = $timeouts[$step] ?? 0;
                $output = $pInfo['outBuf'].$pInfo['errBuf']
                    ."\n[Castor hard timeout after {$stepTimeout}s]";
            } else {
                $exitCode = proc_close($pInfo['handle']);
                $output = $pInfo['outBuf'].$pInfo['errBuf'];

                // Belt-and-suspenders: PG kill for any survivors.
                _reap_process_group($pInfo['sid']);
            }

            // ── Kill any descendant that survived session + PG kills ──
            // pgrep -P descendants + ps sid scan give us the full set.
            foreach (array_merge($descendantPids, $sessionPids) as $pid) {
                @posix_kill($pid, \SIGKILL);
            }

            if (null !== $pInfo['log']) {
                @mkdir(dirname($pInfo['log']), 0755, true);
                file_put_contents($pInfo['log'], $output."\n");
            }

            $results[$step] = [
                'exitCode' => $exitCode,
                'output' => $output,
                'duration' => $elapsed,
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
 * Reap all processes in a process group.
 *
 * Sends SIGTERM to the group, waits a short grace period, then sends
 * SIGKILL.  Surviving grandchildren (e.g. messenger:consume with
 * --time-limit=3600) that escaped shell/timeout/phpunit exit are
 * killed even though their original parent has already terminated.
 *
 * Falls back to _kill_descendant_tree when no PGID is available.
 */
function _reap_process_group(?int $pgid): void
{
    if (null === $pgid || $pgid <= 1) {
        return;
    }

    // Collect the full descendant tree BEFORE sending any signals.
    // Once intermediate parents exit (from kill(-PGID)), grandchild
    // pids are reparented to init/systemd and pgrep -P can no longer
    // find them.  Snapshot first, then kill everything we captured.
    $descendantPids = _collect_descendant_pids($pgid);

    // Best-effort SIGTERM to the process group.
    // kill(-pgid) targets all processes with that PGID even after
    // the original leader (setsid) has exited and children have been
    // reparented to init/systemd.
    @posix_kill(-$pgid, \SIGTERM);
    usleep(500_000); // 0.5 s grace
    @posix_kill(-$pgid, \SIGKILL);

    // Kill every captured descendant individually so grandchildren in
    // separate process groups (e.g. messenger:consume workers spawned
    // by the controller with their own PGID) are also reaped.
    foreach ($descendantPids as $pid) {
        @posix_kill($pid, \SIGKILL);
    }
}

/**
 * Recursively collect ALL descendant PIDs of a process.
 *
 * Walks pgrep -P depth-first to build a complete snapshot of the
 * process tree before intermediate parents exit.  The result includes
 * grandchildren in separate process groups (setsid, new PGID).
 *
 * @return list<int>
 */
function _collect_descendant_pids(int $pid): array
{
    if ($pid <= 1) {
        return [];
    }
    $children = _find_child_pids($pid);
    $pids = [];
    foreach ($children as $child) {
        $pids[] = $child;
        $pids = array_merge($pids, _collect_descendant_pids($child));
    }

    return $pids;
}

/**
 * Kill a process and all its descendants recursively.
 *
 * Uses pgrep -P to discover child PIDs, kills them depth-first,
 * then kills the parent.  Prevents orphaned grandchildren that
 * inherit pipe file descriptors and block proc_close/stream reads.
 */
function _kill_descendant_tree(int $pid, int $signal): void
{
    if ($pid <= 1) {
        return;
    }
    $children = _find_child_pids($pid);
    foreach ($children as $child) {
        _kill_descendant_tree($child, $signal);
    }
    @posix_kill($pid, $signal);
}

/**
 * Find immediate child PIDs of a process using pgrep.
 *
 * Returns an empty list when pgrep is unavailable or finds no children.
 *
 * @return list<int>
 */
function _find_child_pids(int $ppid): array
{
    if ($ppid <= 0) {
        return [];
    }
    $output = [];
    @exec("pgrep -P {$ppid} 2>/dev/null", $output);
    $pids = [];
    foreach ($output as $line) {
        $pid = (int) trim($line);
        if ($pid > 0) {
            $pids[] = $pid;
        }
    }

    return $pids;
}

// ── Session-scoped cleanup (primary mechanism) ──────────────

/**
 * Collect all PIDs in a session using ps -eo pid=,sid=.
 *
 * Unlike pgrep -P (parent-based tree walk), session lookup finds
 * ALL processes in a session regardless of intermediate parent
 * survival.  This catches grandchildren in separate PGIDs (e.g.
 * messenger:consume workers spawned via the controller with their
 * own setsid) that pgrep -P misses after the intermediate parent
 * exits.
 *
 * @return list<int>
 */
function _collect_session_pids(int $sid): array
{
    if ($sid <= 1) {
        return [];
    }
    $output = [];
    @exec('ps -eo pid=,sid= 2>/dev/null', $output);
    $pids = [];
    $myPid = getmypid();
    foreach ($output as $line) {
        $fields = preg_split('/\s+/', trim($line), 2);
        if (2 !== count($fields)) {
            continue;
        }
        $pid = (int) $fields[0];
        $psSid = (int) $fields[1];
        // Skip ourselves and init.
        if ($pid <= 1 || $pid === $myPid) {
            continue;
        }
        if ($psSid === $sid) {
            $pids[] = $pid;
        }
    }

    return $pids;
}

/**
 * Reap all processes in a session: SIGTERM, grace, SIGKILL.
 *
 * This is the PRIMARY cleanup mechanism.  It catches every process
 * in the step's session, including grandchildren that created their
 * own process groups (messenger:consume, agent --controller) and are
 * invisible to kill(-PGID).
 *
 * Paired with _reap_process_group() for belt-and-suspenders coverage.
 */
function _reap_session(?int $sid): void
{
    if (null === $sid || $sid <= 1) {
        return;
    }

    // Collect before signaling so we can individually kill survivors.
    $pids = _collect_session_pids($sid);

    // Best-effort SIGTERM to all session PIDs individually.
    foreach ($pids as $pid) {
        @posix_kill($pid, \SIGTERM);
    }
    usleep(500_000); // 0.5 s grace

    // SIGKILL any survivors.
    foreach ($pids as $pid) {
        @posix_kill($pid, \SIGKILL);
    }

    // Also SIGKILL via kill(0, -sid) — this sends to every process
    // in the process group whose value is $sid.  Since SID=PGID for
    // the session leader, this catches any process still lingering
    // in the leader's PG.
    @posix_kill(-$sid, \SIGKILL);
}

// ── Timeout hard-stop smoke proof task ──────────────────────

/**
 * Smoke-proof that the Castor parallel runner:
 *
 * 1. Does not hang when a child process spawns grandchildren that
 *    hold stdout/stderr pipes open after the timeout fires.
 *
 * 2. Reaps escaped grandchildren on NORMAL exit, not only timeout.
 *    Even if the step exits 0, leaked messenger:consume / controller /
 *    tmux-agent grandchildren must be killed.
 *
 * 3. Session-based cleanup (_reap_session) kills same-SID separate-PGID
 *    grandchildren (e.g. messenger:consume workers that get their own
 *    process group via job control).
 *
 * 4. Descendant-tree cleanup catches separate-SID separate-PGID
 *    grandchildren (e.g. setsid'd sub-processes).
 */
#[AsTask(name: 'test:timeout-hardstop', description: 'Verify Castor hard timeout + normal-exit session + descendant-tree cleanup without hangs')]
function test_timeout_hardstop(string $cmdOverride = ''): void
{
    echo "=== Castor timeout hard-stop + normal-exit cleanup smoke proof ===\n\n";
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $ok = true;

    // ── Test A: Timeout leak (original proof) ──────────────────
    echo "── Test A: Hard timeout kills leaked children ──\n\n";

    // Build a command that spawns a background grandchild holding
    // stdout/stderr open.  The shell `timeout` wraps a 30s timeout
    // but our Castor-level 3s timeout should fire first.
    //
    // Use `wait` to keep the shell alive while the background sleep
    // runs, so the pipe write ends remain open until the timeout kills
    // the full process tree.
    $leakyCmd = '' !== $cmdOverride
        ? $cmdOverride
        : 'timeout --kill-after=5s 30s sh -lc \'sleep 120 & echo "main done"; wait\' 2>&1';

    echo "Command under test:\n  {$leakyCmd}\n\n";

    $commands = [
        'leaky-test' => [
            'cmd' => $leakyCmd,
            'log' => report_path('check-test-timeout-hardstop.log'),
        ],
    ];

    $timeouts = ['leaky-test' => 3]; // Castor hard timeout: 3 s

    $preCount = count_alive_descendants();

    $start = hrtime(true);
    $results = run_commands_parallel($commands, $timeouts);
    $duration = (hrtime(true) - $start) / 1e9;

    $result = $results['leaky-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$result['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $result['duration']);
    echo "  output: {$result['output']}\n\n";

    // 1. Must return fast (< 8 s, generous buffer above the 3 s timeout + 2 s grace + kill)
    if ($duration > 8.0) {
        echo "FAIL: runner took {$duration}s > 8s — likely hung\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$duration}s (< 8s)\n";
    }

    // 2. Should be timeout exit code (124) or killed-by-termination (143).
    $exitOk = in_array($result['exitCode'], [124, 143], true);
    if (!$exitOk) {
        echo "FAIL: expected exit code 124 (timeout) or 143 (SIGTERM), got {$result['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code {$result['exitCode']} (timeout/SIGTERM)\n";
    }

    // 3. No orphan processes should remain (sleep / sh from the leaky command)
    usleep(1_000_000); // 1s settle
    $postCount = count_alive_descendants();
    if ($postCount > $preCount) {
        echo "FAIL: {$postCount} orphan processes remain (pre={$preCount})\n";
        $ok = false;
    } else {
        echo "PASS: no orphan processes (pre={$preCount}, post={$postCount})\n";
    }

    // ── Test B: Normal-exit leak ──────────────────────────────
    echo "\n── Test B: Normal-exit cleanup kills leaked children ──\n\n";

    // Command exits 0 immediately while leaving sleep 120 in the
    // background.  The _reap_process_group() call after proc_close
    // must kill that sleep via kill(-PGID, SIGTERM/SIGKILL).
    $exitLeakCmd = 'sh -lc \'sleep 120 & echo "exit-ok"; exit 0\'';

    echo "Command under test:\n  {$exitLeakCmd}\n\n";

    $commandsB = [
        'exit-leak-test' => [
            'cmd' => $exitLeakCmd,
            'log' => report_path('check-test-exit-leak.log'),
        ],
    ];

    $preCountB = count_alive_descendants();

    $startB = hrtime(true);
    $resultsB = run_commands_parallel($commandsB, []);
    $durationB = (hrtime(true) - $startB) / 1e9;

    $resultB = $resultsB['exit-leak-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$resultB['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $resultB['duration']);
    echo "  output: {$resultB['output']}\n\n";

    // 1. Exit code must be 0 (normal success).
    if (0 !== $resultB['exitCode']) {
        echo "FAIL: expected exit code 0 (normal), got {$resultB['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code 0 (normal)\n";
    }

    // 2. Runner must return fast (< 5 s).
    if ($durationB > 5.0) {
        echo "FAIL: runner took {$durationB}s > 5s\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$durationB}s (< 5s)\n";
    }

    // 3. No orphan sleep must survive the normal-exit cleanup.
    usleep(1_000_000); // 1s settle
    $postCountB = count_alive_descendants();
    if ($postCountB > $preCountB) {
        echo "FAIL: {$postCountB} orphan processes remain after normal exit (pre={$preCountB})\n";
        $ok = false;
    } else {
        echo "PASS: no orphan processes after normal exit (pre={$preCountB}, post={$postCountB})\n";
    }

    // ── Test C: Startup stale-worker cleanup ─────────────────
    echo "\n── Test C: Startup cleanup_stale_check_workers kills stale PHAR workers ──\n\n";

    // Spawn a fake stale process whose ps output looks like a leaked
    // messenger consumer.  Use array-form proc_open (no intermediate
    // sh -c escaping) with bash + exec -a (dash does not support it).
    // tail -f /dev/null blocks forever; extra args after -- are treated
    // as filenames (harmless; only /dev/null produces output).
    //
    // ps output: <pharPath> -f /dev/null -- messenger:consume fake --time-limit=3600
    $pharPath = $root.'/var/tmp/phar/hatfield.phar';
    $fakeArgs = ['bash', '-c', 'exec -a '.$pharPath
        .' tail -f /dev/null -- messenger:consume fake --time-limit=3600'];

    echo "Fake stale worker args:\n  ".implode(' ', $fakeArgs)."\n\n";

    $fakeProc = @proc_open($fakeArgs, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $fakePipes);
    if (!is_resource($fakeProc)) {
        echo "FAIL: could not start fake stale worker\n";
        $ok = false;
    } else {
        fclose($fakePipes[0]);
        $fakePid = proc_get_status($fakeProc)['pid'];
        echo "Fake stale worker PID: {$fakePid}\n";

        // Give the child a moment to exec.
        usleep(500_000);

        // Verify ps shows the expected shape.
        $psLine = '';
        @exec('ps -p '.$fakePid.' -o args= 2>/dev/null', $psLines);
        if ([] !== $psLines) {
            $psLine = trim($psLines[0]);
        }
        echo "ps args: {$psLine}\n";

        $preCountC = count_alive_descendants();
        echo "Pre-cleanup descendants: {$preCountC}\n";

        // Run the startup cleanup helper.
        cleanup_stale_check_workers($root);
        usleep(1_000_000); // 1 s settle

        $postCountC = count_alive_descendants();
        echo "Post-cleanup descendants: {$postCountC}\n";

        // Reap the proc handle first so zombies are cleaned up.
        // kill -9 leaves a zombie until the parent wait()s; posix_kill
        // returns true for zombies, so close the handle before checking.
        @fclose($fakePipes[1]);
        @fclose($fakePipes[2]);
        @proc_close($fakeProc);
        usleep(200_000);

        // Now check if the PID is truly gone.
        $fakeAlive = @posix_kill($fakePid, 0);
        if ($fakeAlive) {
            // Double-check: maybe it's a zombie adopted elsewhere.
            $psState = (string) @shell_exec('ps -p '.$fakePid.' -o state= 2>/dev/null');
            $isZombie = str_contains($psState, 'Z');
            if ($isZombie) {
                echo "PASS: fake stale PID {$fakePid} is zombie (cleaned); will be reaped by init\n";
            } else {
                echo "FAIL: fake stale PID {$fakePid} still alive after cleanup (state={$psState})\n";
                $ok = false;
            }
        } else {
            echo "PASS: fake stale PID {$fakePid} killed by startup cleanup\n";
        }
    }

    // ── Test D: Same-SID separate-PGID grandchild cleanup (session-based) ──
    echo "\n── Test D: Session cleanup kills grandchild in separate PGID (same SID) ──\n\n";

    // Simulate the real-world case: a test (PHPUnit) spans a worker
    // (messenger:consume) that gets its own PGID but shares the SID
    // with the Castor step.  'set -m' enables bash job control so
    // the background sleep gets its own process group.
    // The step exits normally; _reap_session() must find and kill the
    // grandchild by SID even though it has a different PGID.
    $separatePgidCmd = "bash -lc 'set -m; sleep 120 & echo \"session-leak\"; exit 0'";

    echo "Command under test:\n  {$separatePgidCmd}\n\n";

    $commandsD = [
        'session-leak-test' => [
            'cmd' => $separatePgidCmd,
            'log' => report_path('check-test-session-leak.log'),
        ],
    ];

    $preCountD = count_alive_descendants();

    $startD = hrtime(true);
    $resultsD = run_commands_parallel($commandsD, []);
    $durationD = (hrtime(true) - $startD) / 1e9;

    $resultD = $resultsD['session-leak-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$resultD['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $resultD['duration']);
    echo "  output: {$resultD['output']}\n\n";

    if (0 !== $resultD['exitCode']) {
        echo "FAIL: expected exit code 0 (normal), got {$resultD['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code 0 (normal)\n";
    }

    if ($durationD > 5.0) {
        echo "FAIL: runner took {$durationD}s > 5s\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$durationD}s (< 5s)\n";
    }

    usleep(1_000_000); // 1s settle
    $postCountD = count_alive_descendants();
    if ($postCountD > $preCountD) {
        echo "FAIL: {$postCountD} orphan processes remain after session cleanup (pre={$preCountD})\n";
        $ok = false;
    } else {
        echo "PASS: no session orphans after separate-PGID same-SID cleanup (pre={$preCountD}, post={$postCountD})\n";
    }

    // ── Test E: Separate-SID grandchild cleanup (descendant-tree fallback) ──
    echo "\n── Test E: Normal-exit cleanup kills grandchild in separate SID (descendant tree) ──\n\n";

    // Spawn a command that creates a grandchild in its own session via
    // setsid (extreme case: different SID AND different PGID).  Session
    // cleanup alone can't reach it; descendant-tree kill must catch it.
    $separateSidCmd = "bash -lc 'setsid sleep 120 & echo \"sid-exit\"; exit 0'";

    echo "Command under test:\n  {$separateSidCmd}\n\n";

    $commandsE = [
        'sid-leak-test' => [
            'cmd' => $separateSidCmd,
            'log' => report_path('check-test-sid-leak.log'),
        ],
    ];

    $preCountE = count_alive_descendants();

    $startE = hrtime(true);
    $resultsE = run_commands_parallel($commandsE, []);
    $durationE = (hrtime(true) - $startE) / 1e9;

    $resultE = $resultsE['sid-leak-test'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    echo "Result:\n";
    echo "  exitCode: {$resultE['exitCode']}\n";
    echo sprintf("  duration: %.2fs\n", $resultE['duration']);
    echo "  output: {$resultE['output']}\n\n";

    if (0 !== $resultE['exitCode']) {
        echo "FAIL: expected exit code 0 (normal), got {$resultE['exitCode']}\n";
        $ok = false;
    } else {
        echo "PASS: exit code 0 (normal)\n";
    }

    if ($durationE > 5.0) {
        echo "FAIL: runner took {$durationE}s > 5s\n";
        $ok = false;
    } else {
        echo "PASS: runner returned in {$durationE}s (< 5s)\n";
    }

    usleep(1_000_000); // 1s settle
    $postCountE = count_alive_descendants();
    if ($postCountE > $preCountE) {
        echo "FAIL: {$postCountE} orphan processes remain after separate-SID cleanup (pre={$preCountE})\n";
        $ok = false;
    } else {
        echo "PASS: no orphans after separate-SID descendant-tree cleanup (pre={$preCountE}, post={$postCountE})\n";
    }

    if ($ok) {
        echo "\n✅ All timeout + normal-exit + startup-cleanup + session + separate-PGID + separate-SID assertions passed.\n";
    } else {
        echo "\n❌ Some assertions FAILED.\n";
        exit(1);
    }
}

/**
 * Count processes that are descendants of the current Castor process
 * or that look like leaked test artifacts (sleep, sh, messenger).
 */
function count_alive_descendants(): int
{
    $myPid = getmypid();
    $output = [];
    @exec("pgrep -P {$myPid} 2>/dev/null", $output);
    $pids = array_values(array_filter(array_map('intval', $output), static fn ($p) => $p > 0));
    // Also count any sleep/sh/messenger orphans from our CWD.
    $cwd = (string) getcwd();
    $extra = [];
    @exec('ps -eo pid=,cmd= 2>/dev/null', $extra);
    $count = count($pids);
    $pat = preg_quote($cwd, '/');
    foreach ($extra as $line) {
        if (preg_match('/sleep\s+\d+|messenger:consume|sh\s+-lc/', $line) && preg_match("/{$pat}/", $line)) {
            ++$count;
        }
    }

    return $count;
}

/**
 * Kill stale workers from previous castor check runs in this checkout.
 *
 * Matches leaked PHAR messenger:consume / agent --controller children,
 * stale vendor/bin/phpunit processes, and orphaned castor check runs
 * rooted in the current checkout.  Safe: scoped to current project root
 * only.  Does not touch sibling worktrees, the llama.cpp server, or the
 * current castor process.  Silent when no stale workers exist.
 */
function cleanup_stale_check_workers(string $root): void
{
    $output = [];
    @exec('ps -eo pid=,args= 2>/dev/null', $output);
    if ([] === $output) {
        return;
    }

    $myPid = getmypid();
    $pharGlob = $root.'/var/tmp/phar/hatfield.phar';
    $pharPat = preg_quote($pharGlob, '/');

    $rootPat = preg_quote($root, '/');
    $pidsToKill = [];
    foreach ($output as $raw) {
        $line = trim($raw);
        if ('' === $line) {
            continue;
        }
        // Parse leading pid then command.  `ps -eo pid=,args=` produces
        // padded columns: "  1234 /usr/bin/php ..."
        if (!preg_match('/^\s*(\d+)\s+(.+)$/s', $line, $m)) {
            continue;
        }
        $pid = (int) $m[1];
        if ($pid === $myPid || $pid <= 1) {
            continue;
        }
        $cmdline = $m[2];

        // Skip processes not rooted in this checkout.
        if (!preg_match("/{$rootPat}/", $cmdline)) {
            continue;
        }

        // ── Leaked messenger consumers / agent controllers ──
        // PHAR-based E2E tests spawn messenger:consume and
        // agent --controller children with --time-limit=3600 that
        // can survive the test process.  Match by the checkout's
        // own PHAR binary path.
        if (preg_match("/{$pharPat}/", $cmdline)
            && (str_contains($cmdline, 'messenger:consume')
                || str_contains($cmdline, 'agent --controller'))) {
            $pidsToKill[] = $pid;
            continue;
        }

        // ── Stale vendor/bin/phpunit with this checkout's root ──
        if (str_contains($cmdline, 'vendor/bin/phpunit')) {
            $pidsToKill[] = $pid;
            continue;
        }

        // ── Stale castor check with this checkout's root ──
        // (The self-PID guard above prevents killing ourselves.)
        if (str_contains($cmdline, 'castor check')) {
            $pidsToKill[] = $pid;
            continue;
        }
    }

    if ([] === $pidsToKill) {
        return;
    }

    $pidList = implode(' ', $pidsToKill);
    echo "Killing stale check worker PIDs: {$pidList}\n";

    // SIGTERM first, short grace, then SIGKILL survivors.
    foreach ($pidsToKill as $pid) {
        @posix_kill($pid, \SIGTERM);
    }
    usleep(1_000_000);
    foreach ($pidsToKill as $pid) {
        if (@posix_kill($pid, 0)) {
            @posix_kill($pid, \SIGKILL);
        }
    }
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
    $timeouts = [];
    foreach ($steps as $step => $info) {
        $logFiles[$step] = report_path("check-{$step}.log");
        $commands[$step] = [
            'cmd' => $info['cmd'],
            'log' => $logFiles[$step],
        ];
        // Extract the shell-timeout seconds from the step command so
        // Castor can enforce a belt-and-suspenders hard timeout in the
        // poll loop.  Shell timeouts may not kill the full descendant
        // tree; the Castor-level timeout guarantees we never hang on a
        // blocked pipe.
        if (preg_match('/^timeout\s+.*?\s+(\d+)s\s/', $info['cmd'], $m)) {
            // Pad 15 s to avoid racing the shell timeout.  The Castor
            // hard timeout is the safety net; the shell timeout is the
            // primary kill.
            $timeouts[$step] = (int) $m[1] + 15;
        }
    }
    @mkdir(\CastorTasks\REPORTS_DIR, 0755, true);

    echo 'Running steps in parallel (proc_open):
';
    foreach (array_keys($steps) as $step) {
        echo "  - {$step}\n";
    }
    echo '
';

    $results = run_commands_parallel($commands, $timeouts);

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
                $lines = explode("\n", trim($logContent));
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
        $process = @proc_open(
            ['setsid', '-w', 'sh', '-lc', $info['cmd']],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            $duration = (hrtime(true) - $start) / 1e9;
            $timings[$step] = $duration;
            $failures[$step] = 'proc_open failed';
            echo sprintf('FAIL (%.1fs): proc_open failed
', $duration);
            continue;
        }

        $sid = proc_get_status($process)['pid'];

        // Non-blocking incremental read to prevent hanging when
        // grandchildren survive and hold pipe write ends open.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $outBuf = '';
        $errBuf = '';
        $deadline = (hrtime(true) / 1e9) + 75.0; // generous fallback

        while (true) {
            $status = proc_get_status($process);
            $chunk = @fread($pipes[1], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $outBuf .= $chunk;
            }
            $chunk = @fread($pipes[2], 65536);
            if (false !== $chunk && '' !== $chunk) {
                $errBuf .= $chunk;
            }
            if (!$status['running']) {
                break;
            }
            if ((hrtime(true) / 1e9) >= $deadline) {
                _reap_session($sid > 0 ? $sid : null);
                _reap_process_group($sid > 0 ? $sid : null);
                $outBuf .= "\n[Castor sequential hard timeout after 75s]";
                break;
            }
            usleep(50000);
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Reap entire session (primary) + process group (belt-and-suspenders).
        _reap_session($sid > 0 ? $sid : null);
        _reap_process_group($sid > 0 ? $sid : null);

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

function timeout_check_command(string $command, int $seconds): string
{
    return 'timeout --kill-after=15s '.max(1, $seconds).'s sh -lc '.escapeshellarg($command);
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
            $names[] = $nameMat[1];
        }
    }

    $summary = 'risky='.$riskyCount;
    if ([] !== $names) {
        $summary .= ': '.implode(', ', $names);
    }

    return $summary;
}

function format_step_failures(array $failures): string
{
    $lines = [];
    foreach ($failures as $step => $message) {
        $lines[] = '- '.$step.': '.str_replace("\n", "\n  ", $message);
    }

    return implode("\n", $lines);
}
