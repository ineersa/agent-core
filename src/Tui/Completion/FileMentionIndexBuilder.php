<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

use Symfony\Component\Finder\Finder;

/**
 * Scans a project directory with Symfony Finder and produces a
 * JSONL index file for file mention completion.
 *
 * This is intentionally NOT run in the TUI input hot path.
 * Callers (e.g. a CLI command or a periodic tick listener) invoke
 * {@see build()} offline.  The result is read by
 * {@see FileMentionIndexReader}.
 *
 * Atomic-write strategy:
 *   1. Acquire an exclusive lock (flock + lock file).
 *   2. Scan with Finder into an in-memory buffer.
 *   3. Write to a temp file in the same directory as the target.
 *   4. rename(tmp, target) — atomic on the same filesystem.
 *   5. Release lock.
 *
 * On failure (scan error, write error, rename failure) the existing
 * index is left untouched.  The lock is always released.
 *
 * Caps the output to prevent pathological repos from producing
 * unusably large index files.
 */
final readonly class FileMentionIndexBuilder
{
    private const int MAX_ENTRIES = 50_000;
    private const string LOCK_FILE_EXT = '.lock';

    /** @var list<string>|null */
    private ?array $excludeDirs;

    /**
     * @param string            $cwd         Project root to scan
     * @param string            $indexPath   Target JSONL path
     * @param list<string>|null $excludeDirs Directories to exclude beyond the built-in defaults
     */
    public function __construct(
        private string $cwd,
        private string $indexPath,
        ?array $excludeDirs = null,
    ) {
        $this->excludeDirs = $excludeDirs;
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
        $lockPath = $this->indexPath.self::LOCK_FILE_EXT;
        $lockHandle = $this->acquireLock($lockPath);

        try {
            $tmpPath = $this->indexPath.'.tmp.'.getmypid().'.'.hrtime(true);
            $count = $this->scanAndWrite($tmpPath);

            // Atomically replace the existing index.
            if (!@rename($tmpPath, $this->indexPath)) {
                // Clean up tmp file on rename failure.
                @unlink($tmpPath);

                throw new \RuntimeException("Failed to atomically move file mention index from '{$tmpPath}' to '{$this->indexPath}'.");
            }

            return $count;
        } catch (\RuntimeException $re) {
            throw $re;
        } catch (\Throwable $e) {
            // Wrap Finder or filesystem errors so callers always get
            // a consistent RuntimeException interface.
            throw new \RuntimeException("File mention index build failed: {$e->getMessage()}", previous: $e);
        } finally {
            $this->releaseLock($lockHandle);
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
        } catch (\Throwable) {
            // Degrade gracefully if ignoreVCSIgnored triggers errors
            // (e.g. unreadable .gitignore deep in excluded dirs).
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
     * @return resource
     */
    private function acquireLock(string $lockPath)
    {
        $handle = @fopen($lockPath, 'w');
        if (false === $handle) {
            throw new \RuntimeException("Cannot open lock file: {$lockPath}");
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            throw new \RuntimeException('File mention index build already in progress (lock held).');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseLock($handle): void
    {
        flock($handle, \LOCK_UN);
        fclose($handle);
    }
}
