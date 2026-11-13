<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Symfony\Component\Filesystem\Filesystem;

/** Small recovery checkpoints only; remote Workflow remains authoritative. Call under lock(). */
final class WorkspaceStateStore
{
    private readonly string $directory;
    private readonly Filesystem $filesystem;

    public function __construct(GitExecutor $git, string $primaryRoot)
    {
        $result = $git->gitOk(['rev-parse', '--path-format=absolute', '--git-common-dir'], $primaryRoot);
        if ($result->cancelled || $result->timedOut) {
            throw new \RuntimeException('Cannot resolve Git common directory.');
        }
        $common = realpath(trim($result->stdout));
        if (false === $common) {
            throw new \RuntimeException('Git common directory does not exist.');
        }
        $this->directory = $common.'/hatfield/tasks';
        $this->filesystem = new Filesystem();
    }

    /** @return array<string, mixed>|null */
    public function load(string $repository, int $issue): ?array
    {
        $path = $this->path($repository, $issue).'.json';
        if (!is_file($path)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($record) || ($record['repository'] ?? null) !== $repository || ($record['issue'] ?? null) !== $issue) {
            throw new \RuntimeException('Invalid workspace recovery identity.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    public function save(string $repository, int $issue, array $record): void
    {
        $record['repository'] = $repository;
        $record['issue'] = $issue;
        $this->filesystem->dumpFile($this->path($repository, $issue).'.json', json_encode($record, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, mixed> */
    public function initialize(string $repository, int $issue, string $url, string $branch, string $worktree): array
    {
        $record = $this->load($repository, $issue);
        if (null !== $record) {
            if ($record['branch'] !== $branch || $record['worktree'] !== $worktree) {
                throw new \RuntimeException('Existing workspace binding differs; recover it before provisioning.');
            }
            $this->filesystem->mkdir($this->path($repository, $issue).'.data');

            return $record;
        }
        // Persist the resource ID before any provisioning side effect. Randomness also
        // distinguishes independent clones while linked checkouts share this record.
        $resource = 'task-'.bin2hex(random_bytes(16));
        $data = $this->path($repository, $issue).'.data';
        $record = ['repository' => $repository, 'issue' => $issue, 'url' => $url, 'branch' => $branch, 'worktree' => $worktree, 'resource_id' => $resource, 'data_dir' => $data, 'pending_operation' => null, 'setup_attempted' => false, 'setup_complete' => false, 'cleanup_complete' => false, 'cleanup_reason' => null, 'submitted_sha' => null];
        $this->save($repository, $issue, $record);
        $this->filesystem->mkdir($data);

        return $record;
    }

    /** @return array<string, mixed> */
    public function beginCleanup(string $repository, int $issue, string $reason): array
    {
        if (!\in_array($reason, ['done', 'cancel'], true)) {
            throw new \InvalidArgumentException('Cleanup reason must be done or cancel.');
        }
        $record = $this->load($repository, $issue) ?? throw new \RuntimeException('Workspace recovery record is missing.');
        if (\in_array($record['pending_operation'], ['done', 'cancel'], true) && $record['pending_operation'] !== $reason) {
            throw new \RuntimeException('Conflicting pending finalization; explicit reconciliation required.');
        }
        // Invalidate before the hook: partial teardown must never be resumed as setup.
        $record['setup_complete'] = false;
        $record['pending_operation'] = $reason;
        $this->save($repository, $issue, $record);

        return $record;
    }

    public function lock(string $repository, int $issue): TaskBoardLock
    {
        return new TaskBoardLock($this->path($repository, $issue).'.lock');
    }

    /** Shared by all linked worktrees and issues, not just one repository identity. */
    public function primaryLock(): TaskBoardLock
    {
        return new TaskBoardLock($this->directory.'/primary.lock');
    }

    /** @return list<array<string, mixed>> */
    public function records(string $repository): array
    {
        $directory = \dirname($this->path($repository, 1));
        $records = [];
        $paths = glob($directory.'/*.json');
        foreach (false === $paths ? [] : $paths as $path) {
            $issue = (int) basename($path, '.json');
            $record = $this->load($repository, $issue);
            if (null !== $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /** Caller must confirm remote finalization before discarding recovery and hook data. */
    public function forget(string $repository, int $issue): void
    {
        $path = $this->path($repository, $issue);
        $this->filesystem->remove([$path.'.data', $path.'.json']);
        // Keep the lock inode: unlinking it would let another process lock a new inode.
    }

    private function path(string $repository, int $issue): string
    {
        if ($issue < 1 || !preg_match('{^(?:[A-Za-z0-9.-]+/)?[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$}', $repository)) {
            throw new \InvalidArgumentException('Expected repository [host/]owner/name and positive issue number.');
        }

        $identity = 2 === substr_count($repository, '/') ? $repository : 'github.com/'.$repository;

        return $this->directory.'/'.hash('sha256', strtolower($identity)).'/'.$issue;
    }
}
