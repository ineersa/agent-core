<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\Tui\Completion\FileMentionIndexEntryDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Scans a project directory with Symfony Finder and produces a
 * JSONL index file for file mention completion.
 *
 * This is intentionally NOT run in the TUI input hot path.
 * Callers (e.g. a CLI command invoked by Symfony Scheduler) invoke
 * {@see build()} offline.  The result is read by
 * {@see \Ineersa\Tui\Completion\FileMentionIndexReader}.
 *
 * Locking uses Symfony Lock (injected {@see LockFactory}) with a
 * named lock keyed by the index path hash.  Non-blocking acquire
 * prevents concurrent builds without hand-rolled lock files.
 *
 * Atomic-write strategy:
 *   1. Acquire a non-blocking named lock via LockFactory.
 *   2. Scan with Finder into an in-memory buffer.
 *   3. Write to a temp file, flush (fflush), and close.
 *   4. rename(tmp, target) — atomic on the same filesystem.
 *   5. Release lock.
 *
 * On failure (scan error, write error, rename failure) the existing
 * index is left untouched.  The lock is always released.
 *
 * Caps the output to prevent pathological repos from producing
 * unusably large index files.
 */
final class FileMentionIndexBuilder
{
    private const int MAX_ENTRIES = 50_000;

    /** @var list<string>|null */
    private readonly ?array $excludeDirs;

    /** Cleanup-tracked temp path for atomic-write safety. */
    private ?string $tmpPath = null;

    private readonly ?LoggerInterface $logger;

    /**
     * @param string               $cwd         Project root to scan
     * @param string               $indexPath   Target JSONL path
     * @param list<string>|null    $excludeDirs Directories to exclude (replaces built-in defaults when provided)
     * @param LoggerInterface|null $logger      Logger for diagnostic events (autowired by DI)
     * @param LockFactory|null     $lockFactory Lock factory for build exclusion (autowired by DI)
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $indexPath,
        ?array $excludeDirs = null,
        ?LoggerInterface $logger = null,
        private readonly ?LockFactory $lockFactory = null,
    ) {
        $this->excludeDirs = $excludeDirs;
        $this->logger = $logger;
    }

    /**
     * Build (or refresh) the file mention index atomically.
     *
     * @return int number of entries written
     *
     * @throws \RuntimeException when the scan or atomic write fails
     *                           after the lock is acquired — caller should log and
     *                           retry later
     */
    public function build(): int
    {
        $lock = $this->acquireLock();

        if (null === $lock) {
            throw new FileMentionIndexLockHeldException();
        }

        try {
            $this->tmpPath = $this->indexPath.'.tmp.'.getmypid().'.'.hrtime(true);
            $count = $this->scanAndWrite($this->tmpPath);

            // Atomically replace the existing index.
            if (!@rename($this->tmpPath, $this->indexPath)) {
                // Clean up tmp file on rename failure.
                @unlink($this->tmpPath);

                throw new \RuntimeException("Failed to atomically move file mention index from '{$this->tmpPath}' to '{$this->indexPath}'.");
            }

            $this->tmpPath = null;

            return $count;
        } catch (\RuntimeException $re) {
            // Clean up tmp file on scan/write failure as well — the
            // exception path through scanAndWrite doesn't close the
            // tmp handle (finally does it), but the partial file is
            // left on disk unless cleaned here.
            if (null !== $this->tmpPath) {
                @unlink($this->tmpPath);
            }

            throw $re;
        } catch (\Throwable $e) {
            // Clean up tmp file before wrapping Finder/filesystem
            // errors into a consistent RuntimeException interface.
            if (null !== $this->tmpPath) {
                @unlink($this->tmpPath);
            }

            throw new \RuntimeException("File mention index build failed: {$e->getMessage()}", previous: $e);
        } finally {
            $lock->release();
        }
    }

    // ─── Internal ──────────────────────────────────────────────────

    private function scanAndWrite(string $tmpPath): int
    {
        $tmpDir = \dirname($tmpPath);
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $handle = @fopen($tmpPath, 'w');
        if (false === $handle) {
            throw new \RuntimeException("Cannot open temp index file for writing: {$tmpPath}");
        }

        try {
            $count = 0;

            // Collect entries from Finder, encoding as JSONL.
            foreach ($this->scanEntries() as $entry) {
                if ($count >= self::MAX_ENTRIES) {
                    break;
                }

                $line = json_encode([
                    'path' => $entry->path,
                    'dir' => $entry->isDirectory,
                ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

                if (false === $line) {
                    continue; // Should not happen for simple scalars.
                }

                fwrite($handle, $line."\n");
                ++$count;
            }

            // Flush buffered writes to the OS before rename so the
            // atomic replacement sees complete data.  A partial write
            // would survive rename and produce a corrupt index.
            if (!fflush($handle)) {
                throw new \RuntimeException('Failed to flush buffered writes for file mention index.');
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return \Generator<FileMentionIndexEntryDTO>
     */
    private function scanEntries(): \Generator
    {
        $finder = Finder::create()
            ->in($this->cwd)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs(true)
            ->ignoreDotFiles(false)
            ->exclude($this->excludeDirs ?? self::defaultExcludeDirs())
        ;

        // ignoreVCSIgnored(true) is best-effort for .gitignore support.
        // In Git worktrees where .git is a file (not a directory), the
        // root-detection heuristic in VcsIgnoredFilterIterator may not
        // find the actual repo root, so explicit excludes are mandatory.
        try {
            $finder->ignoreVCSIgnored(true);
        } catch (\Throwable $e) {
            // Intentional local degradation: .gitignore-aware filtering
            // failed — fall back to explicit excludes only.  The
            // completed index may include more entries than desired,
            // but completion remains functional.
            $this->logger->debug(
                'File mention index: ignoreVCSIgnored unavailable, falling back to explicit excludes.',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.vcs_ignored_unavailable',
                    'message' => $e->getMessage(),
                ],
            );
        }

        foreach ($finder as $splFileInfo) {
            $realPath = $splFileInfo->getRealPath();

            if (false === $realPath) {
                continue;
            }

            $relativePath = $this->toRelativePath($realPath);

            if (null === $relativePath) {
                continue;
            }

            yield new FileMentionIndexEntryDTO(
                path: $relativePath,
                isDirectory: $splFileInfo->isDir(),
            );
        }
    }

    /**
     * Convert an absolute filesystem path to a relative path from CWD.
     *
     * Returns null when the path is outside the CWD (symlink escape
     * or abnormal Finder behaviour).
     */
    private function toRelativePath(string $absolutePath): ?string
    {
        $cwd = rtrim($this->cwd, '/').'/';

        if (!str_starts_with($absolutePath, $cwd)) {
            return null;
        }

        $relative = substr($absolutePath, \strlen($cwd));

        // Normalise directory separators to forward slashes.
        return str_replace('\\', '/', $relative);
    }

    /**
     * @return list<string>
     */
    private static function defaultExcludeDirs(): array
    {
        return [
            '.git',
            'vendor',
            'node_modules',
            'var',
            '.hatfield/sessions',
            '.hatfield/tmp',
            '.hatfield/cache',
        ];
    }

    /**
     * Acquire a named lock keyed by the index path.
     *
     * Uses non-blocking acquire (false) so a scheduler refresh
     * immediately returns null without waiting behind an in-progress
     * index build — it does not pile up blocked workers.
     *
     * @return LockInterface|null acquired lock, or null when already held
     */
    private function acquireLock(): ?LockInterface
    {
        // Hash the index path to create a stable, short resource name
        // that is safe for lock backends without character restrictions.
        $resourceKey = 'file_mention_index.'.hash('xxh32', $this->indexPath);

        $lock = $this->lockFactory->createLock($resourceKey, ttl: 300.0);

        if (!$lock->acquire(false)) {
            return null;
        }

        return $lock;
    }
}
