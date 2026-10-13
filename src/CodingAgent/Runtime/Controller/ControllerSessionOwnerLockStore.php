<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;

/** Non-expiring controller locks with framework-coordinated directory reclamation. */
final class ControllerSessionOwnerLockStore implements PersistingStoreInterface
{
    private readonly string $root;
    private readonly Filesystem $filesystem;
    private readonly LockFactory $coordinator;

    public function __construct(string $directory)
    {
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($directory);
        $root = realpath($directory);
        if (false === $root) {
            throw new LockStorageException('Controller lock directory cannot be resolved.');
        }
        $this->root = $root;
        $this->coordinator = new LockFactory(new FlockStore($root));
    }

    public function save(Key $key): void
    {
        $this->synchronized(function () use ($key): void {
            $resource = (string) $key;
            $directory = $this->directory($resource);
            $this->recover($directory);
            if (file_exists($directory) || is_link($directory)) {
                $this->requireOwnedDirectory($directory, $resource);
            } else {
                $this->filesystem->mkdir($directory);
                // Publish ownership before FlockStore opens any session inode.
                $this->filesystem->dumpFile($directory.'/owner', $resource);
            }
            (new FlockStore($directory))->save($key);
        });
    }

    public function delete(Key $key): void
    {
        $this->synchronized(function () use ($key): void {
            $directory = $this->directory((string) $key);
            if (!file_exists($directory) && !is_link($directory)) {
                return;
            }
            $this->requireOwnedDirectory($directory, (string) $key);
            (new FlockStore($directory))->delete($key);
            $this->removeStoppedDirectory($directory, (string) $key);
        });
    }

    public function exists(Key $key): bool
    {
        return $this->synchronized(function () use ($key): bool {
            $directory = $this->directory((string) $key);
            if (!file_exists($directory) && !is_link($directory)) {
                return false;
            }
            $this->requireOwnedDirectory($directory, (string) $key);

            return (new FlockStore($directory))->exists($key);
        });
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        // FlockStore has no expiration; controller ownership is process-lifetime.
    }

    private function directory(string $resource): string
    {
        if (1 !== preg_match('/^hatfield\.controller\.session\.[a-f0-9]{64}$/D', $resource)) {
            throw new LockStorageException('Invalid controller session lock resource.');
        }

        return $this->root.'/managed-'.hash('sha256', $resource);
    }

    private function requireOwnedDirectory(string $directory, string $resource): void
    {
        if (realpath($directory) !== $directory || is_link($directory)
            || !is_file($directory.'/owner') || is_link($directory.'/owner')
            || file_get_contents($directory.'/owner') !== $resource) {
            throw new LockStorageException('Controller session lock directory has uncertain ownership.');
        }
    }

    private function recover(string $except): void
    {
        foreach (new \DirectoryIterator($this->root) as $entry) {
            $directory = $entry->getPathname();
            if ($directory === $except || !$entry->isDir() || $entry->isLink()
                || 1 !== preg_match('/^managed-[a-f0-9]{64}$/D', $entry->getFilename())
                || !is_file($directory.'/owner') || is_link($directory.'/owner')) {
                continue;
            }
            $resource = file_get_contents($directory.'/owner');
            if (false === $resource || 1 !== preg_match('/^hatfield\.controller\.session\.[a-f0-9]{64}$/D', $resource)
                || $directory !== $this->directory($resource)) {
                continue;
            }
            $this->requireOwnedDirectory($directory, $resource);
            $this->removeStoppedDirectory($directory, $resource);
        }
    }

    private function removeStoppedDirectory(string $directory, string $resource): void
    {
        $store = new FlockStore($directory);
        $probe = (new LockFactory($store))->createLock($resource, ttl: null);
        if (!$probe->acquire(blocking: false)) {
            // An owner or inherited consumer still holds this inode. Retention
            // is intentional, not permission to unlink a live session lock.
            return;
        }
        try {
            // Every opener holds the permanent coordinator, including failed
            // contenders. No process can have opened an unacquired old inode.
            $this->filesystem->remove($directory);
        } finally {
            $probe->release();
        }
    }

    /** @template T
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function synchronized(\Closure $operation): mixed
    {
        // Never unlink this permanent coordinator inode. It protects opens as
        // well as reclamation; session locks remain independent after opening.
        $lock = $this->coordinator->createLock('controller-session-directory-lifecycle', ttl: null);
        $lock->acquire(blocking: true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
