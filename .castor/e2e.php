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

use function CastorTasks\check_lane_paratest_processes;
use function CastorTasks\check_llm_generation_ready;
use function CastorTasks\ensure_standalone_tui_qa_run_id;
use function CastorTasks\finalize_qa_run_tui_tmux_sessions;
use function CastorTasks\is_llm_mode;
use function CastorTasks\phar_ensure;
use function CastorTasks\qa_test_home_shell_prefix;
use function CastorTasks\report_path;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/phpunit.php';
require_once __DIR__.'/env.php';
require_once __DIR__.'/qa_tmux.php';

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
    $envPrefix = qa_check_run_env_command().' HATFIELD_QA_LANE=llm-real APP_ENV=test LLAMA_CPP_SMOKE_TEST=1 ';

    // Full group: ParaTest parallel (was a single sequential PHPUnit process).
    // Filtered runs stay sequential — ParaTest --filter can be unreliable.
    if (null === $filter && class_exists(ParaTest\ParaTestCommand::class)) {
        $bootstrap = paratest_bootstrap_path();

        return $envPrefix.\PHP_BINARY.' vendor/bin/paratest'
            .' --configuration=phpunit.xml.dist'
            .' --bootstrap='.escapeshellarg($bootstrap)
            .' --group=llm-real'
            .' --exclude-group=recording'
            .' --processes='.check_lane_paratest_processes('llm-real', 1, 4)
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
    $envPrefix = qa_check_run_env_command().' HATFIELD_QA_LANE=tui APP_ENV=test ';

    // TuiArtifactBootE2eTest hard-requires a packaged binary. Always ensure the
    // worktree PHAR for test:tui (full group + filters + castor check lane) so
    // the proof cannot soft-pass with a missing artifact.
    try {
        $pharPath = phar_ensure();
        $envPrefix .= 'HATFIELD_BINARY_PATH='.escapeshellarg($pharPath).' HATFIELD_REQUIRE_ARTIFACT=1 ';
        echo "TUI artifact env: HATFIELD_BINARY_PATH={$pharPath}\n";
    } catch (Throwable $e) {
        throw new RuntimeException('TUI artifact proof requires a packaged PHAR (castor phar:ensure failed): '.$e->getMessage(), 0, $e);
    }

    $filterArg = null !== $filter ? ' --filter='.escapeshellarg($filter) : '';
    if ('' === $filterArg) {
        $filterArg = ' --group=tui-e2e-replay';
    }

    if (null === $filter && class_exists(ParaTest\ParaTestCommand::class)) {
        $bootstrap = paratest_bootstrap_path();
        $processes = check_lane_paratest_processes('tui', 2, 4);
        $legacy = getenv('HATFIELD_TUI_PARATEST_PROCESSES');
        if (false !== $legacy && '' !== trim((string) $legacy)) {
            $legacyInt = (int) $legacy;
            if ($legacyInt >= 1 && $legacyInt <= 4) {
                $processes = $legacyInt;
            }
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

    // Session-aware runner reaps PHPUnit/ParaTest + messenger/controller children.
    // Hard cap is castor_test_runner_max_seconds() (210s).
    $result = run_test_command_bounded(
        'llm-real',
        $cmd,
        castor_test_runner_max_seconds(),
        report_path('check-test-llm-real.log'),
    );
    $duration = $result['duration'];

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

    if (124 === $result['exitCode']) {
        fail_quality(sprintf('LLM real smoke tests timed out after %.1fs', $duration));
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('LLM real smoke tests failed in %.1fs (exit code %d)', $duration, $result['exitCode']));
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

    $tuiQaRunId = ensure_standalone_tui_qa_run_id();

    // ParaTest bootstrap migrates per-worker DBs; sequential full group still needs default DB.
    if (null !== $filter || !class_exists(ParaTest\ParaTestCommand::class)) {
        @mkdir('var/test', 0755, true);
        $migrate = run_test_db_migrate_bounded(
            qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
                .' && '.qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --em=messenger_transport --configuration=config/migrations/messenger_transport.yaml --no-interaction --allow-no-migration'
        );
        if (0 !== $migrate['exitCode']) {
            $detail = '' !== trim($migrate['output']) ? $migrate['output'] : 'exit '.$migrate['exitCode'];
            fail_quality('test database migration failed: '.$detail);
        }
    }

    $cmd = build_test_tui_phpunit_command($filter);

    echo "\n=== TUI E2E journey tests (replay-backed, no live LLM) ===\n\n";

    $result = ['exitCode' => 1, 'output' => '', 'duration' => 0.0];
    try {
        $result = run_test_command_bounded(
            'tui',
            $cmd,
            castor_test_runner_max_seconds(),
            report_path('check-test-tui.log'),
        );
    } finally {
        finalize_qa_run_tui_tmux_sessions($tuiQaRunId);
    }
    if ('' !== $result['output']) {
        echo $result['output'];
    }
    $duration = $result['duration'];

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('tui');
        if ('' !== $summary) {
            echo "{$summary}\n";
        }
    }

    if (124 === $result['exitCode']) {
        fail_quality(sprintf('TUI E2E journey tests timed out after %.1fs', $duration));
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('TUI E2E journey tests failed in %.1fs (exit code %d)', $duration, $result['exitCode']));
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
    $cmd = qa_observability_env_command().' APP_ENV=test '
        .'HATFIELD_UPDATE_SNAPSHOTS=1 '
        .\PHP_BINARY.' vendor/bin/phpunit'
        .' --group tui-e2e-replay'
        .' --colors=never --no-progress --do-not-cache-result'
        .(is_llm_mode() ? ' --log-junit='.report_path('phpunit-tui-update.junit.xml') : '');
    $result = run_test_command_bounded('tui-update', $cmd, castor_test_runner_max_seconds());
    if ('' !== $result['output']) {
        echo $result['output'];
    }

    echo sprintf('

TUI snapshot update complete (exit code %d).
', $result['exitCode']);
    if (124 === $result['exitCode']) {
        exit(124);
    }
    if (0 !== $result['exitCode']) {
        exit($result['exitCode']);
    }
}

// ─── Controller E2E ──────────────────────────────────────────────

#[AsTask(name: 'test:controller', description: 'Run controller E2E smoke tests (live LLM, opt-in)')]
function test_controller(): void
{
    check_llm_generation_ready();

    @mkdir('var/test', 0755, true);
    $migrate = run_test_db_migrate_bounded(
        qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
            .' && '.qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --em=messenger_transport --configuration=config/migrations/messenger_transport.yaml --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate['exitCode']) {
        $detail = '' !== trim($migrate['output']) ? $migrate['output'] : 'exit '.$migrate['exitCode'];
        fail_quality('test database migration failed: '.$detail);
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

    $cmd = qa_observability_env_command().' APP_ENV=test '.$pharEnv.'LLAMA_CPP_SMOKE_TEST=1 '.\PHP_BINARY.' vendor/bin/phpunit'
        .' --filter=ControllerSmokeTest'
        .' '.$strictFlags.$llmFlags;

    $result = run_test_command_bounded('controller', $cmd, castor_test_runner_max_seconds());
    if ('' !== $result['output']) {
        echo $result['output'];
    }
    $duration = $result['duration'];

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('controller');
        if ('' !== $summary) {
            echo "{$summary}
";
        }
    }

    if (124 === $result['exitCode']) {
        fail_quality(sprintf('Controller E2E tests timed out after %.1fs', $duration));
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('Controller E2E tests failed in %.1fs (exit code %d)', $duration, $result['exitCode']));
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
    $migrate = run_test_db_migrate_bounded(
        qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
            .' && '.qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --em=messenger_transport --configuration=config/migrations/messenger_transport.yaml --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate['exitCode']) {
        $detail = '' !== trim($migrate['output']) ? $migrate['output'] : 'exit '.$migrate['exitCode'];
        fail_quality('test database migration failed: '.$detail);
    }

    // Controller replay E2E must NOT use PHAR: the test DI replay
    // factory (ControllerReplayHttpClientFactory in tests/) is wired
    // through config/services_test.yaml, which requires source-tree
    // autoload-dev paths.  The PHAR bundles only production autoload
    // classes.  HATFIELD_BINARY_PATH is intentionally not set here.

    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = is_llm_mode() ? ' --colors=never --no-progress --log-junit='.report_path('phpunit-controller-replay.junit.xml') : '';

    $cmd = qa_observability_env_command().' APP_ENV=test '.\PHP_BINARY.' vendor/bin/phpunit'
        .' --group=controller-replay'
        .' '.$strictFlags.$llmFlags;

    echo "\n=== Controller Replay E2E (deterministic, no live LLM) ===\n\n";

    $result = run_test_command_bounded('controller-replay', $cmd, castor_test_runner_max_seconds());
    if ('' !== $result['output']) {
        echo $result['output'];
    }
    $duration = $result['duration'];

    if (is_llm_mode()) {
        $summary = read_suite_junit_summary('controller-replay');
        if ('' !== $summary) {
            echo "{$summary}\n";
        }
    }

    if (124 === $result['exitCode']) {
        fail_quality(sprintf('Controller replay E2E tests timed out after %.1fs', $duration));
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('Controller replay E2E tests failed in %.1fs (exit code %d)', $duration, $result['exitCode']));
    }
    echo sprintf('\nOK (%.1fs)\n', $duration);
    exit(0);
}
