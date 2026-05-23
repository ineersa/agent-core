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
 * Run full QA: deptrac, phpunit, phpstan, cs-fixer check.
 */
#[AsTask(description: 'Run full QA (deptrac, phpunit, phpstan, cs-fixer)')]
function check(): void
{
    $failures = [];

    foreach ([
        'deptrac' => static fn () => deptrac(),
        'test' => static fn () => test(),
        'phpstan' => static fn () => phpstan(),
        'cs-check' => static fn () => cs_check(),
    ] as $step => $runner) {
        try {
            $runner();
        } catch (Throwable $exception) {
            $failures[] = sprintf('%s: %s', $step, $exception->getMessage());
        }
    }

    if ([] !== $failures) {
        throw new RuntimeException("quality failed:\n - ".implode("\n - ", $failures));
    }

    echo 'quality: ok'.\PHP_EOL;
}

/**
 * Alias for check().
 */
#[AsTask(description: 'Alias for check')]
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
        throw new RuntimeException(sprintf('deptrac failed (%s); report=%s; log=%s', $summary, relative_report_path('deptrac.json'), relative_report_path('deptrac.log')));
    }

    echo sprintf(
        'deptrac: ok (%s)',
        $summary,
    ).\PHP_EOL;
}

/**
 * Run PHPUnit tests (excludes tmux e2e and real LLM smoke tests).
 *
 * TUI e2e tests require tmux and are environment-sensitive.
 * Run them explicitly with "castor test:tui".
 * LLM smoke tests hit real providers; use "castor test:llm-real".
 */
#[AsTask(description: 'Run PHPUnit tests (excludes tmux e2e and real LLM smoke tests)')]
function test(string $filter = ''): void
{
    $cmd = 'vendor/bin/phpunit --exclude-group tui-e2e --exclude-group llm-real';
    if ('' !== $filter) {
        $cmd .= ' --filter='.escapeshellarg($filter);
    }

    if (!is_llm_mode()) {
        run($cmd.' --colors=always');

        return;
    }

    $junitPath = report_path('phpunit.junit.xml');
    $process = run_quiet_command($cmd.' --colors=never --no-progress --no-results --log-junit '.$junitPath);
    persist_process_output($process, 'phpunit.log');

    $summary = summarize_junit_xml($junitPath);

    if (0 !== $process->getExitCode()) {
        throw new RuntimeException(sprintf('test failed (%s); junit=%s; log=%s', $summary, relative_report_path('phpunit.junit.xml'), relative_report_path('phpunit.log')));
    }

    echo sprintf(
        'test: ok (%s); junit=%s',
        $summary,
        relative_report_path('phpunit.junit.xml'),
    ).\PHP_EOL;
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
        throw new RuntimeException(sprintf('cs-fix failed (%s); report=%s; log=%s', $summary, relative_report_path('php-cs-fixer.json'), relative_report_path('php-cs-fixer.log')));
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
        throw new RuntimeException(sprintf('cs-check failed (%s); report=%s; log=%s', $summary, relative_report_path('php-cs-fixer-check.json'), relative_report_path('php-cs-fixer-check.log')));
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
     * errors. We only throw when there are genuine new errors (totals.errors > 0).
     */
    $decoded = json_decode($stdout, true);
    $newErrors = $decoded['totals']['errors'] ?? 0;
    if (0 !== $process->getExitCode() && 0 !== $newErrors) {
        throw new RuntimeException(sprintf('phpstan failed (%s); report=%s; log=%s', $summary, relative_report_path('phpstan.json'), relative_report_path('phpstan.log')));
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
        'check' => 'Run full QA (deptrac, phpunit, phpstan, cs-fixer).',
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
        'log:tail' => 'Show recent log entries.',
        'log:search' => 'Search log entries.',
        'log:files' => 'List log files.',
        'log:clear' => 'Remove old rotated logs.',
        'run:agent' => 'Launch the agent TUI in tmux.',
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

// ─── tmux TUI tasks ───────────────────────────────────────

// ─── tmux TUI e2e test tasks ─────────────────────────────

/**
 * Run PHPUnit TUI e2e tests (requires tmux).
 *
 * These tests launch the agent in detached tmux sessions
 * and compare snapshots against golden fixtures.  They are
 * NOT included in "castor check" because they require tmux
 * and are environment-sensitive.
 */
#[AsTask(name: 'test:tui', description: 'Run TUI e2e snapshot tests (requires tmux)')]
function test_tui(): void
{
    run('vendor/bin/phpunit --group tui-e2e --colors=always');
}

/**
 * Run TUI e2e tests and UPDATE golden snapshots.
 *
 * Use after intentional rendering changes.  Review the diff
 * before committing updated fixtures.
 */
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
function test_llm_real(): void
{
    run('LLAMA_CPP_SMOKE_TEST=1 vendor/bin/phpunit --group llm-real --colors=always');
}

/**
 * Run the controller E2E smoke test.
 *
 * Spawns `agent --controller` as a subprocess, sends JSONL commands
 * over stdin, reads JSONL events from stdout, and asserts the full
 * async runtime pipeline (controller event loop -> messenger consumers
 * -> LLM invocation -> event delivery).
 *
 * Uses the fast llama_cpp_test/lfm2.5 model on port 9052.
 * Same fast test model as test:llm-real and TUI E2E tests.
 */
#[AsTask(name: 'test:controller', description: 'Run controller E2E smoke test (spawns --controller, sends JSONL)')]
function test_controller(): void
{
    run('LLAMA_CPP_SMOKE_TEST=1 vendor/bin/phpunit --filter ControllerSmokeTest --colors=always');
}

#[AsTask(name: 'test:tui-update', description: 'Run TUI e2e tests and update golden snapshots')]
function test_tui_update(): void
{
    run('HATFIELD_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --group tui-e2e --colors=always');
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
 * Launch the agent TUI in a tmux session.
 *
 * Inside tmux: creates a new window named "hatfield-agent".
 * Outside tmux: creates or attaches to a session named "hatfield-agent".
 *
 * No relaunch loop — the TUI runs once and exits naturally.
 */
#[AsTask(name: 'run:agent', description: 'Launch the agent TUI in a tmux session (hatfield-agent)')]
function run_agent(): void
{
    check_tmux();

    $root = realpath(__DIR__.'/..');
    $session = 'hatfield-agent';
    $insideTmux = false !== getenv('TMUX');

    $innerCmd = sprintf(
        'cd %s && exec php bin/console agent',
        escapeshellarg($root)
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
            "ai:\n    default_model: llama_cpp_test/lfm2.5\n    default_reasoning: off\n",
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

    $innerCmd = sprintf(
        'cd %s && APP_ENV=dev HOME=%s %s %s agent --prompt=%s --model=llama_cpp_test/lfm2.5 --reasoning=off 2>&1',
        escapeshellarg($testDir),
        escapeshellarg($homeDir),
        escapeshellarg(\PHP_BINARY),
        escapeshellarg($root.'/bin/console'),
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
        foreach (['metadata.yaml', 'state.json', 'events.jsonl', 'transcript.jsonl', 'idempotency.jsonl'] as $file) {
            $path = $sessionDir.'/'.$file;
            $out .= is_file($path)
                ? "--- {$file} ---\n".(string) file_get_contents($path)."\n\n"
                : "--- {$file}: missing ---\n\n";
        }
    }

    return $out;
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
