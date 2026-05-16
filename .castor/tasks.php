<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;

use function Castor\run;
use function CastorTasks\build_idea_run_config_xml;

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
    run('vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress');
}

/**
 * Run PHPUnit tests (excludes tmux e2e).
 *
 * TUI e2e tests require tmux and are environment-sensitive.
 * Run them explicitly with "castor test:tui".
 */
#[AsTask(description: 'Run PHPUnit tests (excludes tmux e2e)')]
function test(): void
{
    run('vendor/bin/phpunit --exclude-group tui-e2e --colors=always');
}

/**
 * Run CS fixer (fix in place).
 */
#[AsTask(description: 'Run PHP CS Fixer (fix in place)')]
function cs_fix(): void
{
    run('vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php');
}

/**
 * Run CS fixer dry-run (check only).
 */
#[AsTask(description: 'Run PHP CS Fixer (dry-run, check only)')]
function cs_check(): void
{
    run('vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff');
}

/**
 * Run PHPStan static analysis.
 */
#[AsTask(description: 'Run PHPStan static analysis')]
function phpstan(): void
{
    run('vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress');
}

/**
 * Remove generated QA caches.
 */
#[AsTask(name: 'cache:clear', description: 'Remove generated QA caches (deptrac, php-cs-fixer, phpstan)')]
function cache_clear(): void
{
    $files = [
        __DIR__.'/../.deptrac.cache',
        __DIR__.'/../.php-cs-fixer.cache',
    ];
    $dirs = [
        __DIR__.'/../var/phpstan',
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
        'test' => 'Run PHPUnit tests excluding tmux e2e.',
        'phpstan' => 'Run PHPStan static analysis.',
        'cs-fix' => 'Run PHP CS Fixer and modify files in place.',
        'cs-check' => 'Run PHP CS Fixer dry-run check.',
        'cache:clear' => 'Remove generated QA caches.',
        'run:agent' => 'Launch the agent TUI in tmux.',
        'run:agent-test' => 'Launch deterministic tmux session for snapshot testing.',
        'test:tui' => 'Run TUI e2e snapshot tests.',
        'test:tui-update' => 'Run TUI e2e tests and update golden snapshots.',
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

    $realPath = realpath($path) ?: $path;
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

    $snapDir = $root.'/.hatfield/tmp/tui';
    $snapPath = $snapDir.'/latest.txt';
    $metaPath = $snapDir.'/agent-test.env';

    if (!is_dir($snapDir)) {
        mkdir($snapDir, 0777, true);
    }

    // Tear down any previous test session
    shell_exec(sprintf('tmux kill-session -t %s 2>/dev/null', escapeshellarg($session)));

    // Build a single bash -c command: run agent (blocks until user exits).
    // The TUI event loop keeps the process alive until Ctrl+D or double Ctrl+C.
    $innerCmd = sprintf(
        'cd %s && php bin/console agent --prompt="hello from tui test"',
        escapeshellarg($root)
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
        echo "ERROR: Failed to create tmux session.\n";
        echo 'Command: '.$cmd."\n";
        echo 'Output: '.($output ?? '<none>')."\n";

        return;
    }

    // Some tmux servers ignore new-session -x/-y and keep the global
    // default-size (often 80x24). Force the intended deterministic size.
    shell_exec(sprintf(
        'tmux resize-window -t %s -x %d -y %d 2>/dev/null',
        escapeshellarg($session),
        $width,
        $height
    ));

    // Wait for the command to start and render.
    sleep(2);

    // Plain-text snapshot.
    $snapshot = shell_exec(sprintf('tmux capture-pane -p -t %s', escapeshellarg($paneId)));
    if (null !== $snapshot) {
        file_put_contents($snapPath, $snapshot);
    }

    // Write metadata for automation.
    $meta = [
        'session' => $session,
        'pane_id' => $paneId,
        'width' => $width,
        'height' => $height,
        'snapshot_path' => $snapPath,
        'root' => $root,
        'created_at' => date('c'),
    ];
    file_put_contents($metaPath, json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

    // Print instructions.
    echo 'Test session:  '.$session."\n";
    echo 'Pane:         '.$paneId."\n";
    echo 'Snapshot:     '.$snapPath."\n";
    echo 'Metadata:     '.$metaPath."\n";
    echo "\n";
    echo 'Inspect:      tmux attach-session -t '.$session."\n";
    echo 'Plain snap:   tmux capture-pane -p -t '.$paneId."\n";
    echo 'ANSI snap:    tmux capture-pane -p -e -t '.$paneId."\n";
    echo 'Send keys:    tmux send-keys -t '.$paneId." Enter\n";
    echo 'Tear down:    tmux kill-session -t '.$session."\n";
}
