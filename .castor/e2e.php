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
 *   - test:tui-live → opt-in live LLM TUI E2E (requires llama.cpp).
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

// ─── Real LLM smoke ──────────────────────────────────────────────

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
        $pharPath = phar_ensure();
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

// ─── TUI E2E (replay-backed default) ────────────────────────────

#[AsTask(name: 'test:tui', description: 'Run TUI E2E journey tests (replay-backed, no live LLM)')]
function test_tui(?string $filter = null): void
{
    $filterArg = null !== $filter ? ' --filter='.escapeshellarg($filter) : '';
    check_tmux();

    @mkdir('var/test', 0755, true);
    $migrate = run_quiet_command(
        'APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate->getExitCode()) {
        fail_quality('test database migration failed: '.$migrate->getErrorOutput());
    }

    // Run the replay-backed TUI journey test (plus golden-snapshot test)
    // as a single PHPUnit invocation.  Both use APP_ENV=test + source
    // bin/console with ControllerReplayHttpClientFactory from
    // config/services_test.yaml.  No PHAR, no HATFIELD_BINARY_PATH —
    // the test DI requires autoload-dev paths.
    $groupArg = '' !== $filterArg
        ? $filterArg
        : ' --group tui-e2e-replay';

    $cmd = 'APP_ENV=test '.\PHP_BINARY.' vendor/bin/phpunit'
        .$groupArg
        .' '.phpunit_strict_issue_flags()
        .(is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-tui.junit.xml') : '');

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

#[AsTask(name: 'test:tui-live', description: 'Run TUI E2E tests with live LLM (opt-in, requires llama.cpp)')]
function test_tui_live(?string $filter = null): void
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
        $pharPath = phar_ensure();
    } catch (Throwable $e) {
        echo "PHAR ensure skipped: {$e->getMessage()}\n";
    }
    if ('' !== $pharPath) {
        $GLOBALS['CASTOR_PHAR_READY'] = $pharPath;
    }
    $pharEnv = '' !== $pharPath ? 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' ' : '';

    $cmd = 'APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.\PHP_BINARY.' vendor/bin/phpunit'
        .' --group tui-e2e'
        .$filterArg
        .' '.phpunit_strict_issue_flags()
        .(is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-tui-live.junit.xml') : '');

    echo "\n=== TUI E2E live-LLM tests (opt-in) ===\n\n";

    $start = hrtime(true);
    passthru($cmd, $exitCode);
    $duration = (hrtime(true) - $start) / 1e9;

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('tui-live');
        if ('' !== $summary) {
            echo "{$summary}\n";
        }
    }

    if (0 !== $exitCode) {
        fail_quality(sprintf('TUI E2E live tests failed in %.1fs (exit code %d)', $duration, $exitCode));
    }
    echo sprintf('\nOK (%.1fs)\n', $duration);
    exit(0);
}

#[AsTask(name: 'test:tui-update', description: 'Update TUI E2E snapshot baselines')]
function test_tui_update(): void
{
    check_tmux();
    check_llm_generation_ready();

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
