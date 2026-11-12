<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

use Ineersa\HatfieldExt\TaskWorkflow\DTO\WorkspaceBindingDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;

final class WorktreeManager
{
    public function __construct(private readonly GitExecutor $git)
    {
    }

    public function primaryRoot(string $cwd, ?InvocationControl $control = null): string
    {
        $entries = $this->inspect($cwd, $control);

        return $entries[0]['path'] ?? throw new \RuntimeException('No primary Git checkout found.');
    }

    /** @return list<array{path: string, branch: string, head: string}> */
    public function inspect(string $primaryRoot, ?InvocationControl $control = null): array
    {
        $entries = [];
        $entry = null;
        foreach (explode("\0", $this->run(['worktree', 'list', '--porcelain', '-z'], $primaryRoot, $control)) as $field) {
            if (str_starts_with($field, 'worktree ')) {
                if (null !== $entry) {
                    $entries[] = $entry;
                }
                $entry = ['path' => substr($field, 9), 'branch' => '', 'head' => ''];
            } elseif (null !== $entry && str_starts_with($field, 'branch refs/heads/')) {
                $entry['branch'] = substr($field, 18);
            } elseif (null !== $entry && str_starts_with($field, 'HEAD ')) {
                $entry['head'] = substr($field, 5);
            }
        }
        if (null !== $entry) {
            $entries[] = $entry;
        }

        return $entries;
    }

    public function create(string $primaryRoot, string $branch, string $path, string $baseRef, ?InvocationControl $control = null): WorkspaceBindingDTO
    {
        $primaryRoot = $this->primaryRoot($primaryRoot, $control);
        $this->assertPath($path);
        $this->run(['check-ref-format', '--branch', $branch], $primaryRoot, $control);
        foreach ($this->inspect($primaryRoot, $control) as $entry) {
            if ($entry['path'] === $path) {
                if ($entry['branch'] !== $branch || !is_dir($path) || is_link($path)) {
                    throw new \RuntimeException('Registered workspace does not match requested binding.');
                }

                return new WorkspaceBindingDTO($branch, $path);
            }
        }
        if (false !== @lstat($path)) {
            throw new \RuntimeException('Unregistered workspace path already exists.');
        }
        $exists = $this->git->branchExists($primaryRoot, $branch, $control);
        $this->run($exists ? ['worktree', 'add', '--', $path, $branch] : ['worktree', 'add', '-b', $branch, '--', $path, $baseRef], $primaryRoot, $control);

        // Never roll back partial provisioning: retained state lets cleanup retry safely.
        return new WorkspaceBindingDTO($branch, $path);
    }

    public function isClean(string $path, ?InvocationControl $control = null): bool
    {
        return '' === $this->run(['status', '--porcelain=v1', '-z', '--untracked-files=all'], $path, $control);
    }

    public function head(string $path, ?InvocationControl $control = null): string
    {
        return trim($this->run(['rev-parse', 'HEAD'], $path, $control));
    }

    public function remove(string $primaryRoot, WorkspaceBindingDTO $binding, ?string $expectedHead = null, ?InvocationControl $control = null): void
    {
        $primaryRoot = $this->primaryRoot($primaryRoot, $control);
        $path = $binding->worktree;
        $this->assertPath($path);
        if ($path === $primaryRoot || realpath($path) === realpath($primaryRoot)) {
            throw new \RuntimeException('Refusing primary checkout removal.');
        }
        $registered = null;
        foreach ($this->inspect($primaryRoot, $control) as $entry) {
            if ($entry['path'] === $path) {
                $registered = $entry;
            }
        }
        if (null === $registered) {
            if (!$this->verifyRemoved($primaryRoot, $path, $control)) {
                throw new \RuntimeException('Unregistered path is present; preserving it.');
            }

            return;
        }
        if ($registered['branch'] !== $binding->branch || is_link($path)) {
            throw new \RuntimeException('Workspace ownership mismatch.');
        }
        if (null !== $expectedHead && $registered['head'] !== $expectedHead) {
            throw new \RuntimeException('Workspace HEAD differs from confirmed merged revision.');
        }
        if (false !== @lstat($path)) {
            if (!is_dir($path) || $this->primaryRoot($path, $control) !== $primaryRoot || !$this->isClean($path, $control)) {
                throw new \RuntimeException('Workspace has valuable changes or is not the owned checkout.');
            }
            $actualRoot = trim($this->run(['rev-parse', '--show-toplevel'], $path, $control));
            if ($actualRoot !== $path || (null !== $expectedHead && $this->head($path, $control) !== $expectedHead)) {
                throw new \RuntimeException('Workspace identity changed.');
            }
        }
        // Missing directories still have a registry entry: ask Git to remove it, never prune globally.
        $this->run(['worktree', 'remove', '--', $path], $primaryRoot, $control);
        if (!$this->verifyRemoved($primaryRoot, $path, $control)) {
            throw new \RuntimeException('Worktree removal was not fully confirmed.');
        }
    }

    public function verifyRemoved(string $primaryRoot, string $path, ?InvocationControl $control = null): bool
    {
        clearstatcache(true, $path);
        foreach ($this->inspect($primaryRoot, $control) as $entry) {
            if ($entry['path'] === $path) {
                return false;
            }
        }

        return false === @lstat($path);
    }

    public function fastForwardBase(string $primaryRoot, string $baseBranch, string $remote = 'origin', ?InvocationControl $control = null): void
    {
        $primaryRoot = $this->primaryRoot($primaryRoot, $control);
        $this->run(['check-ref-format', '--branch', $baseBranch], $primaryRoot, $control);
        if (!$this->isClean($primaryRoot, $control) || trim($this->run(['symbolic-ref', '--short', 'HEAD'], $primaryRoot, $control)) !== $baseBranch) {
            throw new \RuntimeException('Primary checkout must be clean and on the configured base branch.');
        }
        $this->run(['fetch', '--', $remote, $baseBranch], $primaryRoot, $control);
        $this->run(['merge', '--ff-only', 'FETCH_HEAD'], $primaryRoot, $control);
    }

    private function assertPath(string $path): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('{/(?:\.|\.\.)(?:/|$)|//$|/$}', $path)) {
            throw new \InvalidArgumentException('Workspace path must be absolute and normalized.');
        }
        $parent = realpath(\dirname($path));
        if (false !== $parent && $parent.'/'.basename($path) !== $path) {
            throw new \InvalidArgumentException('Workspace path must not traverse symlinks.');
        }
    }

    /** @param list<string> $args */
    private function run(array $args, string $cwd, ?InvocationControl $control): string
    {
        if (null !== $control?->interrupted()) {
            throw new \RuntimeException('Workspace operation interrupted.');
        }
        $result = $this->git->gitOk($args, $cwd, 120.0, $control);
        if ($result->cancelled || $result->timedOut) {
            throw new \RuntimeException('Workspace operation interrupted.');
        }

        return $result->stdout;
    }
}
