<?php

declare(strict_types=1);

namespace CastorTasks;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\FlockStore;

require_once __DIR__.'/qa_tmux.php';

/** Development-only ownership for test fixtures, test caches and QA reports. */
final class TestArtifactLifecycle
{
    /** @var array<string, self> */
    private static array $instances = [];

    private readonly string $root;
    private readonly string $manifest;
    private readonly LockFactory $locks;
    private readonly Filesystem $filesystem;
    /** @var array{root: string, id: string, pid: int, start: ?string, paths: list<string>, tmux: bool, qa_run: ?string} */
    private array $owner;
    private ?string $report = null;
    private ?LockInterface $ownerLease = null;
    private bool $ownerFinished = false;

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if (false === $resolved) {
            throw new \RuntimeException('Test artifact project root does not exist.');
        }
        $this->root = $resolved;
        $this->filesystem = new Filesystem();
        $this->manifest = $resolved.'/var/tmp/artifact-owners/'.bin2hex(random_bytes(16)).'.json';
        if (!$this->safeParents($this->manifest)) {
            throw new \RuntimeException('Test artifact ownership directory must not escape through a symlink.');
        }
        $this->filesystem->mkdir($resolved.'/var/tmp/artifact-owners');
        $this->locks = new LockFactory(new FlockStore($resolved.'/var/tmp/artifact-owners'));
        $start = self::processStart(getmypid());
        $qaRun = getenv('HATFIELD_QA_RUN_ID');
        $this->owner = ['root' => $resolved, 'id' => basename($this->manifest, '.json'), 'pid' => getmypid(), 'start' => $start, 'paths' => [], 'tmux' => false, 'qa_run' => false !== $qaRun && '' !== $qaRun ? $qaRun : null];
    }

    /** Publish detached ownership before tmux creates a pane outside our process namespace. */
    public static function registerCurrentTmuxOwner(): ?string
    {
        $id = getenv('HATFIELD_TEST_ARTIFACT_OWNER');
        foreach (self::$instances as $instance) {
            if ($instance->owner['id'] !== $id) {
                continue;
            }

            return $instance->synchronized(static function () use ($instance): string {
                $instance->owner['tmux'] = true;
                $qaRun = getenv('HATFIELD_QA_RUN_ID');
                $instance->owner['qa_run'] = false !== $qaRun && '' !== $qaRun ? $qaRun : null;
                $instance->startOwnerLease();
                $instance->write($instance->manifest, $instance->owner);

                return $instance->owner['id'];
            });
        }

        return null;
    }

    public static function current(string $root): self
    {
        if (!isset(self::$instances[$root])) {
            $instance = new self($root);
            self::$instances[$root] = $instance;
            // exit(), fatal errors and cancellation that reaches PHP shutdown use
            // the same ownership checks as normal completion. SIGKILL is recovered.
            register_shutdown_function(static function () use ($instance): void {
                try {
                    $instance->finish();
                } catch (\Throwable $exception) {
                    // Shutdown cannot report through PHPUnit. Keep the manifest
                    // for recovery and log sanitized intentional degradation.
                    fwrite(\STDERR, json_encode([
                        'event_type' => 'test_artifact.cleanup_failed',
                        'component' => 'test_artifact_lifecycle',
                        'run_id' => null,
                        'session_id' => null,
                        'owner_id' => $instance->owner['id'],
                        'pid' => getmypid(),
                        'exception' => $exception::class,
                    ], \JSON_THROW_ON_ERROR)."\n");
                }
            });
        }

        // Re-select the active root after isolated helper calls. Descendants
        // retain its token even after reparenting or closing their descriptors.
        putenv('HATFIELD_TEST_ARTIFACT_OWNER='.self::$instances[$root]->owner['id']);

        return self::$instances[$root];
    }

    /** Register before creating the resource, so crash recovery never guesses ownership. */
    public function own(string $path): void
    {
        if (!$this->allowedPath($path)) {
            throw new \InvalidArgumentException('Refusing test artifact outside the owned roots: '.$path);
        }
        $this->synchronized(function () use ($path): void {
            if (!\in_array($path, $this->owner['paths'], true)) {
                $this->owner['paths'][] = $path;
                $this->startOwnerLease();
                $this->write($this->manifest, $this->owner);
            }
        });
    }

    public function report(string $path): void
    {
        if (!$this->allowedReport($path)) {
            throw new \InvalidArgumentException('Refusing QA report outside var/reports/qa-*');
        }
        $this->synchronized(function () use ($path): void {
            $this->startOwnerLease();
            $this->filesystem->mkdir($path);
            $this->report = $path;
            $this->write($path.'/.artifact-owner.json', $this->owner + ['completed' => null]);
        });
    }

    /** Forget conclusively removed fixtures, not allocations still awaiting creation. */
    public function forgetRemovedPath(string $path): void
    {
        $this->synchronized(function () use ($path): void {
            if (file_exists($path) || is_link($path)) {
                return;
            }
            $paths = array_values(array_filter(
                $this->owner['paths'],
                static fn (string $owned): bool => $owned !== $path && !str_starts_with($owned, $path.'/'),
            ));
            if ($paths !== $this->owner['paths']) {
                $this->owner['paths'] = $paths;
                $this->write($this->manifest, $this->owner);
            }
        });
    }

    /** @return list<string> reclaimed paths */
    public function finish(): array
    {
        return $this->synchronized(function (): array {
            // autoRelease=false closes only our descriptor. Explicit release()
            // would unlock the shared open-file description held by children.
            $this->ownerLease = null;
            $removed = $this->reclaim($this->manifest, $this->owner, true);
            // The Castor parent also recovers a failed/cancelled worker after
            // its consumers have exited, including workers killed by timeout.
            $removed = [...$removed, ...$this->recoverStoppedOwners()];
            if ($this->ownerFinished && null !== $this->report && !$this->referenced([$this->report, ...$this->owner['paths']], $this->owner)) {
                $this->write($this->report.'/.artifact-owner.json', $this->owner + ['completed' => microtime(true)]);
                $this->report = null;
            }
            $this->pruneReports();

            return $removed;
        });
    }

    /** @return list<string> reclaimed paths */
    public function recover(): array
    {
        return $this->synchronized(function (): array {
            $removed = $this->recoverStoppedOwners();
            $this->pruneReports();

            return $removed;
        });
    }

    /** Permission-normalized removal shared with the existing fixture helper. */
    public static function removePath(string $path): void
    {
        $filesystem = new Filesystem();
        if (is_dir($path) && !is_link($path)) {
            $filesystem->chmod($path, 0700);
            // Normalize directories before descending into them. Never chmod
            // a symlink: chmod would mutate its unrelated external target.
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    $filesystem->chmod($entry->getPathname(), 0700);
                }
            }
        }
        $filesystem->remove($path);
    }

    private function startOwnerLease(): void
    {
        if (null !== $this->ownerLease) {
            return;
        }
        // Publish identity first under the coordinator: a crash during lease
        // allocation still has a recoverable record and has created no fixture.
        $this->write($this->manifest, $this->owner);
        $factory = new LockFactory(new FlockStore($this->leaseDirectory($this->owner['id'])));
        $this->ownerLease = $factory->createLock('owner', ttl: null, autoRelease: false);
        // Never wait under the coordinator for a consumer retained by finish().
        // A surviving child must finish before this producer starts a new lifetime.
        if (!$this->ownerLease->acquire(blocking: false)) {
            $this->ownerLease = null;
            throw new \RuntimeException('Cannot allocate test artifacts while a prior consumer retains the producer lease.');
        }
        $this->ownerFinished = false;
    }

    private function leaseDirectory(string $id): string
    {
        return $this->root.'/var/tmp/artifact-owners/'.$id;
    }

    private function acquireStoppedLease(string $id): ?LockInterface
    {
        $directory = $this->leaseDirectory($id);
        if (!$this->safeParents($directory.'/owner')) {
            return null;
        }
        $factory = new LockFactory(new FlockStore($directory));
        $lease = $factory->createLock('owner', ttl: null);

        return $lease->acquire(blocking: false) ? $lease : null;
    }

    /** @return list<string> */
    private function recoverStoppedOwners(): array
    {
        $removed = [];
        foreach ((false !== ($manifests = glob($this->root.'/var/tmp/artifact-owners/*.json')) ? $manifests : []) as $manifest) {
            $owner = $this->readOwner($manifest);
            if (null === $owner || $this->liveOrUnknownProducer($owner)) {
                continue;
            }
            $removed = [...$removed, ...$this->reclaim($manifest, $owner, false)];
        }

        return $removed;
    }

    /** @param array{root: string, id: string, pid: int, start: ?string, paths: list<string>, tmux: bool, qa_run: ?string} $owner
     * @return list<string>
     */
    private function reclaim(string $manifest, array $owner, bool $own): array
    {
        if ((!$own && $this->liveOrUnknownProducer($owner)) || $this->referenced($owner['paths'], $owner)) {
            return [];
        }
        foreach ($owner['paths'] as $path) {
            if (!$this->allowedPath($path)) {
                return [];
            }
        }
        $lease = $this->acquireStoppedLease($owner['id']);
        if (null === $lease) {
            return [];
        }
        try {
            foreach ($owner['paths'] as $path) {
                self::removePath($path);
            }
            $this->filesystem->remove($manifest);
            // Every opener uses the coordinator; a dead producer cannot hand
            // out new descriptors. Inherited descriptors still holding the
            // old inode would have prevented acquiring this lease above.
            $this->filesystem->remove($this->leaseDirectory($owner['id']));
            if ($own) {
                $this->ownerFinished = true;
                $this->owner['paths'] = [];
            }
        } finally {
            $lease->release();
        }

        return $owner['paths'];
    }

    /** Last 10 conclusively stopped QA runs, irrespective of success or failure. */
    private function pruneReports(): void
    {
        $completed = [];
        foreach ((false !== ($reports = glob($this->root.'/var/reports/qa-*', \GLOB_ONLYDIR)) ? $reports : []) as $report) {
            if (!$this->allowedReport($report) || is_link($report)) {
                continue;
            }
            $marker = $report.'/.artifact-owner.json';
            $owner = $this->readOwner($marker);
            if (null === $owner) {
                continue;
            }
            $data = json_decode((string) file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($data)) {
                continue;
            }
            $thisCompletion = $owner['id'] === $this->owner['id'] && is_numeric($data['completed'] ?? null);
            if ((!$thisCompletion && !is_numeric($data['completed'] ?? null) && $this->liveOrUnknownProducer($owner))
                || $this->referenced([$report, ...$owner['paths']], $owner)) {
                continue;
            }
            $directory = $this->leaseDirectory($owner['id']);
            if (is_dir($directory) || is_link($directory)) {
                $lease = $this->acquireStoppedLease($owner['id']);
                if (null === $lease) {
                    continue;
                }
                $lease->release();
            }
            // A dead producer with no remaining consumers is conclusively
            // stopped even when SIGKILL prevented its completion callback.
            if (!\is_float($data['completed'] ?? null) && !\is_int($data['completed'] ?? null)) {
                $data['completed'] = filemtime($marker);
                $this->write($marker, $data);
            }
            $completed[$report] = $data['completed'];
        }
        arsort($completed, \SORT_NUMERIC);
        foreach (\array_slice(array_keys($completed), 10) as $report) {
            $this->filesystem->remove($report);
        }
    }

    /** @return array{root: string, id: string, pid: int, start: ?string, paths: list<string>, tmux: bool, qa_run: ?string}|null */
    private function readOwner(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!\is_array($data) || ($data['root'] ?? null) !== $this->root || !\is_int($data['pid'] ?? null)
            || !\is_string($data['id'] ?? null) || 1 !== preg_match('/^[a-f0-9]{32}$/D', $data['id'])
            || $data['pid'] <= 0 || !\array_key_exists('start', $data)
            || (null !== $data['start'] && (!\is_string($data['start']) || !ctype_digit($data['start'])))
            || !\is_bool($data['tmux'] ?? null)
            || !\array_key_exists('qa_run', $data) || (null !== $data['qa_run'] && !\is_string($data['qa_run']))
            || !\is_array($data['paths'] ?? null) || !array_is_list($data['paths'])) {
            return null;
        }
        foreach ($data['paths'] as $entry) {
            if (!\is_string($entry) || !$this->allowedPath($entry)) {
                return null;
            }
        }

        return ['root' => $data['root'], 'id' => $data['id'], 'pid' => $data['pid'], 'start' => $data['start'], 'paths' => $data['paths'], 'tmux' => $data['tmux'], 'qa_run' => $data['qa_run']];
    }

    private function allowedReport(string $path): bool
    {
        return 1 === preg_match('~^'.preg_quote($this->root, '~').'/var/reports/qa-[A-Za-z0-9._-]+$~D', $path)
            && $this->safeParents($path);
    }

    private function allowedPath(string $path): bool
    {
        if (str_contains($path, '/../') || str_contains($path, '/./') || str_contains($path, "\0")) {
            return false;
        }
        $tmp = $this->root.'/var/tmp/';
        $projectOwned = str_starts_with($path, $tmp) && $path !== $tmp.'artifact-owners'
            && !str_starts_with($path, $tmp.'artifact-owners/');
        $cacheOwned = 1 === preg_match('~^'.preg_quote($this->root, '~').'/.hatfield/cache-[A-Za-z0-9._-]+$~D', $path);
        // OS fixtures are allocated with a cryptographic suffix by
        // TestDirectoryIsolation; unrelated user temp directories are ineligible.
        $tempRoot = realpath(sys_get_temp_dir());
        $osOwned = false !== $tempRoot && 1 === preg_match('~^'.preg_quote(rtrim($tempRoot, '/'), '~').'/[^/]+-[a-f0-9]{16}$~D', $path);

        return ($projectOwned || $cacheOwned || $osOwned) && $this->safeParents($path);
    }

    private function safeParents(string $path): bool
    {
        $parent = \dirname($path);
        while (!is_dir($parent)) {
            $next = \dirname($parent);
            if ($next === $parent) {
                return false;
            }
            $parent = $next;
        }

        return realpath($parent) === $parent;
    }

    /** @param list<string> $paths
     * @param array{root: string, id: string, pid: int, start: ?string, paths: list<string>, tmux: bool, qa_run: ?string} $owner
     */
    private function referenced(array $paths, array $owner): bool
    {
        $ownerId = $owner['id'];
        $tmux = $owner['tmux'];
        if (!$tmux && null !== $owner['qa_run']) {
            // A detached worker has a different producer token, but still owns
            // its Castor parent's run-scoped staging, cache and report evidence.
            foreach (false !== ($manifests = glob($this->root.'/var/tmp/artifact-owners/*.json')) ? $manifests : [] as $manifest) {
                $consumer = $this->readOwner($manifest);
                if (null !== $consumer && $consumer['tmux'] && $consumer['qa_run'] === $owner['qa_run']) {
                    $tmux = true;
                    break;
                }
            }
        }
        if ($tmux) {
            $sessions = inventory_artifact_owner_tmux_sessions($ownerId, $owner['qa_run']);
            // Unavailable or failed inspection is uncertainty, never evidence
            // that detached consumers outside /proc have finished.
            if (null === $sessions || [] !== $sessions) {
                return true;
            }
        }
        // Castor exports project-relative cache/report paths. They remain
        // consumer evidence when a worker replaces its inherited owner token.
        foreach ($paths as $path) {
            if (str_starts_with($path, $this->root.'/')) {
                $paths[] = substr($path, \strlen($this->root) + 1);
            }
        }
        foreach ((false !== ($processes = glob('/proc/[0-9]*', \GLOB_ONLYDIR)) ? $processes : []) as $proc) {
            if ((int) basename($proc) === getmypid()) {
                continue;
            }
            $cwd = @readlink($proc.'/cwd');
            $cmd = @file_get_contents($proc.'/cmdline');
            $env = @file_get_contents($proc.'/environ');
            // Inherited leases protect owned consumers even during opaque
            // exec transitions. Unrelated unreadable processes are not owners;
            // positive token/path references additionally protect explicit users.
            if (false !== $env && str_contains($env, 'HATFIELD_TEST_ARTIFACT_OWNER='.$ownerId."\0")) {
                return true;
            }
            $references = [false !== $cwd ? $cwd : '', false !== $cmd ? $cmd : '', false !== $env ? $env : ''];
            foreach ((false !== ($descriptors = glob($proc.'/fd/*')) ? $descriptors : []) as $fd) {
                $references[] = false !== ($target = @readlink($fd)) ? $target : '';
            }
            foreach ($paths as $path) {
                foreach ($references as $reference) {
                    if (str_contains($reference, $path)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array{root: string, id: string, pid: int, start: ?string, paths: list<string>, tmux: bool, qa_run: ?string} $owner */
    private function liveOrUnknownProducer(array $owner): bool
    {
        // Leases permit portable normal completion. Without Linux identity,
        // abandoned producers remain uncertain rather than blocking allocation.
        return null === $owner['start'] || self::processStart($owner['pid']) === $owner['start'];
    }

    private static function processStart(int $pid): ?string
    {
        $identity = self::processIdentity($pid);

        return null !== $identity && !\in_array($identity['state'], ['Z', 'X'], true) ? $identity['start'] : null;
    }

    /** @return array{start: string, state: string}|null */
    private static function processIdentity(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (false === $stat || false === ($end = strrpos($stat, ')'))) {
            return null;
        }
        $fields = explode(' ', substr($stat, $end + 2));

        return isset($fields[19]) && ctype_digit($fields[19]) ? ['start' => $fields[19], 'state' => $fields[0]] : null;
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $this->filesystem->dumpFile($path, json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function synchronized(\Closure $operation): mixed
    {
        // One permanent project lock serializes registration/recovery/pruning.
        // Never unlink its inode: contenders may already have opened it.
        $lock = $this->locks->createLock('test-artifact-lifecycle', ttl: null);
        $lock->acquire(blocking: true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
