<?php

declare(strict_types=1);

namespace CastorTasks;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/** Cleanup authority acquired only by exclusive mkdir, not by an existing path. */
final class OwnedTemporaryDirectory
{
    /** @param resource $handle Keeps the allocated inode from being recycled. */
    private function __construct(public readonly string $path, private $handle, private readonly int $uid)
    {
    }

    public static function allocate(string $root, string $name, int $mode = 0o750): self
    {
        if ($name !== basename($name) || str_contains($name, '\\') || \in_array($name, ['', '.', '..'], true)) {
            throw new \InvalidArgumentException('Temporary directory name must be a single path segment.');
        }
        self::ensureCanonicalRoot($root);
        $path = $root.'/'.$name;
        if (!@mkdir($path, $mode)) {
            throw new \RuntimeException('Cannot exclusively allocate temporary directory: '.$path);
        }
        clearstatcache(true);
        $entry = lstat($path);
        $handle = realpath($path) === $path ? fopen($path, 'rb') : false;
        $opened = false === $handle ? false : fstat($handle);
        if (false === $entry || false === $opened || !self::sameDirectory($entry, $opened)) {
            throw new \RuntimeException('Temporary directory identity changed during allocation: '.$path);
        }

        return new self($path, $handle, $entry['uid']);
    }

    public function assertOwned(): void
    {
        clearstatcache(true);
        $entry = @lstat($this->path);
        $opened = fstat($this->handle);
        if (false === $entry || false === $opened || !self::sameDirectory($entry, $opened) || $entry['uid'] !== $this->uid || realpath($this->path) !== $this->path) {
            throw new \RuntimeException('Temporary directory moved, replaced, or redirected: '.$this->path);
        }
    }

    public function remove(bool $normalizePermissions = false): void
    {
        $this->assertOwned();
        if ($normalizePermissions) {
            $this->makeTraversable($this->path);
        }
        $this->assertOwned();
        // Symfony does not traverse symlink entries. These pathname checks
        // reject observable replacements; they are not descriptor-relative IO.
        (new Filesystem())->remove($this->path);
    }

    private function makeTraversable(string $path): void
    {
        $this->assertOwned();
        clearstatcache(true);
        if (realpath($path) !== $path || !is_dir($path) || !chmod($path, 0o700)) {
            throw new \RuntimeException('Cannot safely normalize temporary directory permissions: '.$path);
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isLink() && $entry->isDir()) {
                $this->makeTraversable($entry->getPathname());
            }
        }
    }

    private static function ensureCanonicalRoot(string $root): void
    {
        if (!Path::isAbsolute($root) || Path::canonicalize($root) !== $root || '/' === $root) {
            throw new \InvalidArgumentException('Invalid temporary root: '.$root);
        }
        clearstatcache(true);
        if (!file_exists($root) && !is_link($root)) {
            if ('/' !== \dirname($root)) {
                self::ensureCanonicalRoot(\dirname($root));
            }
            (new Filesystem())->mkdir($root);
            clearstatcache(true);
        }
        if (!is_dir($root) || realpath($root) !== $root) {
            throw new \RuntimeException('Temporary root is not a canonical directory: '.$root);
        }
    }

    /**
     * @param array<array-key, int> $a
     * @param array<array-key, int> $b
     */
    private static function sameDirectory(array $a, array $b): bool
    {
        return 0o040000 === ($a['mode'] & 0o170000) && 0o040000 === ($b['mode'] & 0o170000)
            && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'] && $a['uid'] === $b['uid'];
    }
}
