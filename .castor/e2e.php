<?php

declare(strict_types=1);

/** Offline controller process and TUI E2E tasks. */

use Castor\Attribute\AsTask;
use ParaTest\ParaTestCommand;

use function CastorTasks\check_lane_paratest_processes;
use function CastorTasks\ensure_standalone_tui_qa_run_id;
use function CastorTasks\finalize_qa_run_tui_tmux_sessions;
use function CastorTasks\phar_ensure;
use function CastorTasks\qa_test_home_shell_prefix;
use function CastorTasks\report_path;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';
require_once __DIR__.'/phpunit.php';
require_once __DIR__.'/env.php';
require_once __DIR__.'/qa_tmux.php';

/**
 * Shell command for the TUI replay E2E lane (full group or filter).
 *
 * Shared by `castor test:tui` and the `test:tui` step in `castor check`.
 * Full group uses ParaTest when available; filtered runs stay sequential PHPUnit.
 */
function build_test_tui_phpunit_command(?string $filter = null): string
{
    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = ' --colors=never --no-progress --log-junit='.report_path('phpunit-tui.junit.xml');
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

    if (null === $filter && class_exists(ParaTestCommand::class)) {
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

// ─── TUI E2E (replay-backed default) ────────────────────────────

#[AsTask(name: 'test:tui', description: 'Run TUI E2E journey tests (replay-backed, no live LLM)')]
function test_tui(?string $filter = null): void
{
    check_tmux();

    $tuiQaRunId = ensure_standalone_tui_qa_run_id();

    // ParaTest bootstrap migrates per-worker DBs; sequential full group still needs default DB.
    if (null !== $filter || !class_exists(ParaTestCommand::class)) {
        @mkdir('var/test', 0755, true);
        $migrate = run_test_db_migrate_bounded(
            qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
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

    $summary = read_suite_junit_summary('tui');
    if ('' !== $summary) {
        echo "{$summary}\n";
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
    \CastorTasks\initialize_standalone_qa_run();
    check_tmux();

    echo 'Running TUI E2E tests with snapshot update (replay-backed)...
';
    $cmd = qa_observability_env_command().' APP_ENV=test '
        .'HATFIELD_UPDATE_SNAPSHOTS=1 '
        .\PHP_BINARY.' vendor/bin/phpunit'
        .' --group tui-e2e-replay'
        .' --colors=never --no-progress --do-not-cache-result'
        .' --log-junit='.report_path('phpunit-tui-update.junit.xml');
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

// ─── Offline controller process tests ──────────

#[AsTask(
    name: 'test:controller-replay',
    description: 'Run offline controller protocol and process tests',
)]
function test_controller_replay(): void
{
    \CastorTasks\initialize_standalone_qa_run();
    @mkdir('var/test', 0755, true);
    $migrate = run_test_db_migrate_bounded(
        qa_test_home_shell_prefix().' APP_ENV=test '.\PHP_BINARY.' bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration'
    );
    if (0 !== $migrate['exitCode']) {
        $detail = '' !== trim($migrate['output']) ? $migrate['output'] : 'exit '.$migrate['exitCode'];
        fail_quality('test database migration failed: '.$detail);
    }

    // Use source for the test container and autoload-dev classes.
    // HATFIELD_BINARY_PATH is intentionally not set here.

    $strictFlags = phpunit_strict_issue_flags();
    $llmFlags = ' --colors=never --no-progress --log-junit='.report_path('phpunit-controller-replay.junit.xml');

    $cmd = qa_observability_env_command().' APP_ENV=test '.\PHP_BINARY.' vendor/bin/phpunit'
        .' --group=controller-replay'
        .' '.$strictFlags.$llmFlags;

    echo "\n=== Offline controller process tests ===\n\n";

    $result = run_test_command_bounded('controller-replay', $cmd, castor_test_runner_max_seconds());
    if ('' !== $result['output']) {
        echo $result['output'];
    }
    $duration = $result['duration'];

    $summary = read_suite_junit_summary('controller-replay');
    if ('' !== $summary) {
        echo "{$summary}\n";
    }

    if (124 === $result['exitCode']) {
        fail_quality(sprintf('Controller process tests timed out after %.1fs', $duration));
    }
    if (0 !== $result['exitCode']) {
        fail_quality(sprintf('Controller process tests failed in %.1fs (exit code %d)', $duration, $result['exitCode']));
    }
    echo sprintf('\nOK (%.1fs)\n', $duration);
    exit(0);
}
