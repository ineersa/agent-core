<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;

final class HookRunner
{
    private const CONTEXT_KEYS = ['HATFIELD_TASK_REPOSITORY', 'HATFIELD_TASK_NUMBER', 'HATFIELD_TASK_URL', 'HATFIELD_TASK_BRANCH', 'HATFIELD_TASK_BASE_BRANCH', 'HATFIELD_TASK_WORKTREE', 'HATFIELD_TASK_SETUP_ATTEMPTED', 'HATFIELD_TASK_REASON', 'HATFIELD_TASK_RESOURCE_ID', 'HATFIELD_TASK_DATA_DIR'];

    public function __construct(private readonly ExecInterface $exec)
    {
    }

    /** @param array<string, string> $context Exact HATFIELD_TASK_* contract; root/existence are derived. */
    public function run(string $primaryRoot, string $script, float $timeout, array $context, ?InvocationControl $control = null): ExecResultDTO
    {
        $env = [];
        foreach (self::CONTEXT_KEYS as $key) {
            $env[$key] = $context[$key] ?? throw new \InvalidArgumentException('Missing hook context: '.$key);
        }
        if (!\in_array($env['HATFIELD_TASK_REASON'], ['start', 'review', 'done', 'cancel'], true) || $timeout <= 0 || !is_finite($timeout)) {
            throw new \InvalidArgumentException('Invalid hook reason or timeout.');
        }
        $root = realpath($primaryRoot);
        if (false === $root) {
            throw new \RuntimeException('Primary checkout is missing.');
        }
        $resolved = realpath(str_starts_with($script, '/') ? $script : $root.'/'.$script);
        if (false === $resolved || !is_file($resolved) || !str_starts_with($resolved, $root.'/')) {
            throw new \RuntimeException('Hook script must resolve inside the primary checkout.');
        }
        $env['HATFIELD_PROJECT_ROOT'] = $root;
        $env['HATFIELD_TASK_WORKTREE_EXISTS'] = is_dir($env['HATFIELD_TASK_WORKTREE']) ? '1' : '0';
        // ExecOptions merges inherited environment; explicitly neutralize Bash's
        // noninteractive startup injection instead of relying on --norc alone.
        $env['BASH_ENV'] = '/dev/null';
        $env['GIT_TERMINAL_PROMPT'] = '0';
        $cwd = \in_array($env['HATFIELD_TASK_REASON'], ['done', 'cancel'], true) ? $root : $env['HATFIELD_TASK_WORKTREE'];
        if (null !== ($interrupt = $control?->interrupted())) {
            return new ExecResultDTO(stdout: '', stderr: $interrupt['message'], exitCode: -1, timedOut: isset($interrupt['timed_out']), cancelled: isset($interrupt['cancelled']));
        }

        return $this->exec->exec('bash', ['--noprofile', '--norc', $resolved], new ExecOptionsDTO(cwd: $cwd, timeout: $control?->remainingTimeoutSeconds($timeout) ?? $timeout, env: $env, cancellationToken: $control?->cancellationToken));
    }
}
