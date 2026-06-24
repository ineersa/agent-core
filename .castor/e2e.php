<?php

declare(strict_types=1);

/**
 * End-to-end test tasks: live LLM smoke, TUI E2E snapshots,
 * controller E2E.
 *
 * All E2E tasks require the test LLM server (llama_cpp_test/test on
 * port 9052) and run the PHAR ensure preflight.
 *
 * =========================================================================
 * MAINT-05E: TUI E2E restructured into replay-backed journey tests.
 *   - test:tui      → default replay-backed TUI journey (no live LLM).
 *   - test:controller-replay → controller E2E with replay (no live LLM).
 *   - test:controller → opt-in live LLM controller E2E.
 * =========================================================================
 */

use Castor\Attribute\AsTask;

use function CastorTasks\check_llm_generation_ready;
use function CastorTasks\is_llm_mode;
use function CastorTasks\phar_ensure;
use function CastorTasks\report_path;
use function CastorTasks\run_quiet_command;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/phpunit.php';

// ─── Real LLM smoke ──────────────────────────────────────────────

/**
 * Shell command for the llm-real PHPUnit/ParaTest lane (full group or filter).
 *
 * Shared by `castor test:llm-real` and the `test:llm-real` step in `castor check`.
 * Does not run generation preflight — callers must invoke check_llm_generation_ready().
 */
function build_test_llm_real_phpunit_command(?string $filter = null): string
{
    $filterArg = null !== $filter ? ' --filter='.escapeshellarg($filter) : '';
    if ('' === $filterArg) {
        $filterArg = ' --group llm-real';
    }

    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-llm-real.junit.xml') : '';
    $envPrefix = 'APP_ENV=test LLAMA_CPP_SMOKE_TEST=1 ';

    // Full group: ParaTest parallel (was a single sequential PHPUnit process).
    // Filtered runs stay sequential — ParaTest --filter can be unreliable.
    if (null === $filter && class_exists(ParaTest\ParaTestCommand::class)) {
        $bootstrap = paratest_bootstrap_path();

        return $envPrefix.\PHP_BINARY.' vendor/bin/paratest'
            .' --configuration=phpunit.xml.dist'
            .' --bootstrap='.escapeshellarg($bootstrap)
            .' --group=llm-real'
            .' --exclude-group=recording'
            .' --processes=4'
            .' '.$strictFlags.$llmFlags;
    }

    return $envPrefix.\PHP_BINARY.' vendor/bin/phpunit'
        .$filterArg
        .' --exclude-group=recording'
        .' '.$strictFlags.$llmFlags;
}

/**
 * Shell command for the TUI replay E2E lane (full group or filter).
 *
 * Shared by `castor test:tui` and the `test:tui` step in `castor check`.
 * Full group uses ParaTest when available; filtered runs stay sequential PHPUnit.
 */
function build_test_tui_phpunit_command(?string $filter = null): string
{
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-tui.junit.xml') : '';
    $envPrefix = 'APP_ENV=test ';

    $filterArg = null !== $filter ? ' --filter='.escapeshellarg($filter) : '';
    if ('' === $filterArg) {
        $filterArg = ' --group=tui-e2e-replay';
    }

    if (null === $filter && class_exists(ParaTest\ParaTestCommand::class)) {
        $bootstrap = paratest_bootstrap_path();
        $envProcesses = getenv('HATFIELD_TUI_PARATEST_PROCESSES');
        $processes = (int) (false !== $envProcesses && '' !== $envProcesses ? $envProcesses : '2');
        if ($processes < 1) {
            $processes = 2;
        }
        if ($processes > 4) {
            $processes = 4;
        }

        return $envPrefix.\PHP_BINARY.' vendor/bin/paratest'
            .' --configuration=phpunit.xml.dist'
            .' --bootstrap='.escapeshellarg($bootstrap)
            .$filterArg
            .' --processes='.$processes
            .' '.$strictFlags.$llmFlags;
    }

    return $envPrefix.\PHP_BINARY.' vendor/bin/phpunit'
        .$filterArg
        .' '.$strictFlags.$llmFlags;
}

#[AsTask(name: 'test:llm-real', description: 'Run real LLM smoke tests')]
function test_llm_real(?string $filter = null): void
{
    check_llm_generation_ready();

    $cmd = build_test_llm_real_phpunit_command($filter);

    // Run via session-aware process runner to prevent orphaned PHAR workers
    // (messenger:consume children with --time-limit=3600 that outlive PHPUnit
    // and keep the Castor task alive).  run_commands_parallel() spawns the
    // command inside an isolated session via setsid -w and reaps the ENTIRE
    // session tree on timeout and normal completion, killing separate-PGID
    // grandchildren that passthru() leaves behind. 180s is a safety cap for
    // live controller subprocess startup, multi-turn llm-real tests, and worker teardown.
    $commands = [
        'llm-real' => [
            'cmd' => $cmd,
            'log' => report_path('check-test-llm-real.log'),
        ],
    ];
    $timeouts = ['llm-real' => 180]; // parallel llm-real: controller subprocess + warm proxy replay

    $start = hrtime(true);
    $results = run_commands_parallel($commands, $timeouts);
    $duration = (hrtime(true) - $start) / 1e9;
    $result = $results['llm-real'] ?? ['exitCode' => -1, 'output' => 'no result', 'duration' => 0];

    // Flush captured output.
    if ('' !== $result['output']) {
        echo $result['output'];
    }

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('llm-real');
        if ('' !== $summary) {
            echo "{$summary}
";
        }
    }

    // Timeout exit code (124) from run_commands_parallel is a hard failure.
    if (124 === $result['exitCode']) {
        fail_quality(sprintf('LLM real smoke tests timed out after %.1fs', $result['duration'] ?? $duration));
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('LLM real smoke tests failed in %.1fs (exit code %d)', $result['duration'] ?? $duration, $result['exitCode']));
    }
    echo sprintf('

OK (%.1fs)
', $duration);
    exit(0);
}

// ─── TUI E2E (replay-backed default) ────────────────────────────

#[AsTask(name: 'test:tui', description: 'Run TUI E2E journey tests (replay-backed, no live LLM)')]
function test_tui(?string $filter = null): void
{
    check_tmux();

    // ParaTest bootstrap migrates per-worker DBs; sequential full group still needs default DB.
    if (null !== $filter || !class_exists(ParaTest\ParaTestCommand::class)) {
        @mkdir('var/test', 0755, true);
        $migrate = run_quiet_command(
            'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
        );
        if (0 !== $migrate->getExitCode()) {
            fail_quality('test database migration failed: '.$migrate->getErrorOutput());
        }
    }

    $cmd = build_test_tui_phpunit_command($filter);

    echo "\n=== TUI E2E journey tests (replay-backed, no live LLM) ===\n\n";

    $start = hrtime(true);
    passthru($cmd, $exitCode);
    $duration = (hrtime(true) - $start) / 1e9;

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('tui');
        if ('' !== $summary) {
            echo "{$summary}\n";
        }
    }

    if (0 !== $exitCode) {
        fail_quality(sprintf('TUI E2E journey tests failed in %.1fs (exit code %d)', $duration, $exitCode));
    }
    echo sprintf('\nOK (%.1fs)\n', $duration);
    exit(0);
}

#[AsTask(name: 'test:tui-update', description: 'Update TUI E2E snapshot baselines')]
function test_tui_update(): void
{
    check_tmux();

    echo 'Running TUI E2E tests with snapshot update (replay-backed)...
';
    passthru(
        'APP_ENV=test '.
        'HATFIELD_UPDATE_SNAPSHOTS=1 '.
        \PHP_BINARY.' vendor/bin/phpunit'
        .' --group tui-e2e-replay'
        .' --colors=never --no-progress --do-not-cache-result'
        .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-tui-update.junit.xml') : ''),
        $exitCode,
    );

    echo sprintf('

TUI snapshot update complete (exit code %d).
', $exitCode);
}

// ─── Controller E2E ──────────────────────────────────────────────

#[AsTask(name: 'test:controller', description: 'Run controller E2E smoke tests (live LLM, opt-in)')]
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
        $pharPath = phar_ensure();
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

// ─── Controller Replay E2E (deterministic, no live LLM) ──────────

#[AsTask(
    name: 'test:controller-replay',
    description: 'Run controller E2E smoke tests with replay fixtures (no live LLM)',
)]
function test_controller_replay(): void
{
    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    // Controller replay E2E must NOT use PHAR: the test DI replay
    // factory (ControllerReplayHttpClientFactory in tests/) is wired
    // through config/services_test.yaml, which requires source-tree
    // autoload-dev paths.  The PHAR bundles only production autoload
    // classes.  HATFIELD_BINARY_PATH is intentionally not set here.

    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-controller-replay.junit.xml') : '';

    $cmd = 'APP_ENV=test '.\PHP_BINARY.' vendor/bin/phpunit'
        .' --group=controller-replay'
        .' '.$strictFlags.$llmFlags;

    echo "\n=== Controller Replay E2E (deterministic, no live LLM) ===\n\n";

    $start = hrtime(true);
    passthru($cmd, $exitCode);
    $duration = (hrtime(true) - $start) / 1e9;

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('controller-replay');
        if ('' !== $summary) {
            echo "{$summary}\n";
        }
    }

    if (0 !== $exitCode) {
        fail_quality(sprintf('Controller replay E2E tests failed in %.1fs (exit code %d)', $duration, $exitCode));
    }
    echo sprintf('\nOK (%.1fs)\n', $duration);
    exit(0);
}
