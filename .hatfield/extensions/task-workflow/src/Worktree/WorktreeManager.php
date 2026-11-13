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

                $binding = new WorkspaceBindingDTO($branch, $path);
                $this->assertOwned($primaryRoot, $binding, $control);

                return $binding;
            }
        }
        if (false !== @lstat($path)) {
            throw new \RuntimeException('Unregistered workspace path already exists.');
        }
        $exists = $this->git->branchExists($primaryRoot, $branch, $control);
        $this->run($exists ? ['worktree', 'add', '--', $path, $branch] : ['worktree', 'add', '-b', $branch, '--', $path, $baseRef], $primaryRoot, $control);

        // Never roll back partial provisioning: retained state lets cleanup retry safely.
        $binding = new WorkspaceBindingDTO($branch, $path);
        $this->assertOwned($primaryRoot, $binding, $control);

        return $binding;
    }

    public function assertOwned(string $primaryRoot, WorkspaceBindingDTO $binding, ?InvocationControl $control = null): void
    {
        $primaryRoot = $this->primaryRoot($primaryRoot, $control);
        $path = $binding->worktree;
        $this->assertPath($path);
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
            throw new \RuntimeException('Workspace path is missing or traverses a symlink.');
        }
        $matches = array_filter($this->inspect($primaryRoot, $control), static fn (array $entry): bool => $entry['path'] === $path && $entry['branch'] === $binding->branch);
        if (1 !== \count($matches)) {
            throw new \RuntimeException('Workspace registration does not match the owned binding.');
        }
        $commonArgs = ['rev-parse', '--path-format=absolute', '--git-common-dir'];
        $primaryCommon = realpath(trim($this->run($commonArgs, $primaryRoot, $control)));
        $actualCommon = realpath(trim($this->run($commonArgs, $path, $control)));
        $actualRoot = trim($this->run(['rev-parse', '--show-toplevel'], $path, $control));
        $actualBranch = trim($this->run(['symbolic-ref', '--short', 'HEAD'], $path, $control));
        if (false === $primaryCommon || $actualCommon !== $primaryCommon || $actualRoot !== $path || $actualBranch !== $binding->branch) {
            throw new \RuntimeException('Actual workspace identity differs from the owned repository, path or branch.');
        }
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
            $this->assertOwned($primaryRoot, $binding, $control);
            if (!$this->isClean($path, $control)) {
                throw new \RuntimeException('Workspace has valuable changes.');
            }
            if (null !== $expectedHead && $this->head($path, $control) !== $expectedHead) {
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
        // The caller holds the shared primary-checkout lock across this update.
        $ref = 'refs/hatfield/base-update/'.hash('sha256', $remote."\0".$baseBranch);
        $this->run(['fetch', '--', $remote, 'refs/heads/'.$baseBranch.':'.$ref], $primaryRoot, $control);
        $sha = trim($this->run(['rev-parse', '--verify', $ref.'^{commit}'], $primaryRoot, $control));
        // Another fetch in primary can replace FETCH_HEAD. Merge only the
        // captured immutable commit instead.
        $this->run(['merge', '--ff-only', $sha], $primaryRoot, $control);
        if ($this->head($primaryRoot, $control) !== $sha) {
            throw new \RuntimeException('Primary HEAD does not match the fetched base revision.');
        }
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
