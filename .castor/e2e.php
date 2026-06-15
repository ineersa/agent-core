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
 * FUTURE (MAINT-05C/D/E):
 *   MAINT-05C will introduce deterministic LLM replay mode — these
 *     tasks (or their replay equivalents) will no longer require a
 *     live llama.cpp server for routine QA.
 *   MAINT-05D will add explicit controller/messenger process ownership
 *     contracts so failed E2E tests never leave orphaned consumers.
 *   MAINT-05E will restructure TUI E2E into long-lived journey tests
 *     with far fewer tmux launches; the sharding in here will be
 *     removed when that lands.
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

// ─── TUI E2E ─────────────────────────────────────────────────────

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
        $pharPath = phar_ensure();
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
