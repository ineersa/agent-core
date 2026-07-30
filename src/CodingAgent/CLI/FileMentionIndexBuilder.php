<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Ineersa\Tui\Completion\FileMentionIndexEntryDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo as FinderSplFileInfo;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * Builds a JSONL index for `@` file-mention completion.
 *
 * This is intentionally NOT run in the TUI input hot path. The sole
 * production refresh owner is {@see CompletionFileIndexRefreshCommand}
 * (30s Symfony Scheduler period). An existing readable index is used
 * immediately by {@see \Ineersa\Tui\Completion\FileMentionIndexReader};
 * a missing first-ever index yields empty `@` suggestions until the
 * scheduler refresh succeeds (ponytail: no ad-hoc background process).
 *
 * Enumeration strategy:
 *   - Git worktrees that are not themselves ignored by a parent repo:
 *     stream `git ls-files --cached --others --exclude-standard -z`
 *     (argv Process, no shell). Nested `.gitignore`, `.git/info/exclude`,
 *     and global excludes are honored by Git itself. Parent directories are
 *     inferred from file paths. Git runtime failures fail the atomic
 *     build cleanly so the previous index is preserved.
 *   - Non-Git roots, missing Git, or a CWD ignored by a parent repository:
 *     bounded Symfony Finder with explicit pre-prune of noisy trees.
 *     `ignoreVCSIgnored(true)` is best-effort output filtering only and
 *     is never relied on for directory pruning.
 *
 * Explicit noisy filters (applied to Git results too, because `--cached`
 * can still surface tracked files under those names):
 *   - path components named `.git`, `vendor`, `node_modules`, `var`
 *   - `.hatfield/sessions`, `.hatfield/tmp`, `.hatfield/cache`,
 *     and `.hatfield/cache-*` trees at any nesting
 *
 * Locking uses Symfony Lock (injected {@see LockFactory}) with a
 * named lock keyed by the index path hash. Non-blocking acquire
 * prevents concurrent builds without hand-rolled lock files.
 *
 * Atomic-write strategy:
 *   1. Acquire a non-blocking named lock via LockFactory.
 *   2. Stream entries into a temp file.
 *   3. fflush and close.
 *   4. rename(tmp, target) — atomic on the same filesystem.
 *   5. Release lock.
 *
 * Caps the output to prevent pathological repos from producing
 * unusably large index files. MAX_ENTRIES counts files and inferred
 * directories and bounds dedupe memory for parent directories.
 */
final class FileMentionIndexBuilder
{
    private const int MAX_ENTRIES = 50_000;

    private const int STDERR_DIAGNOSTIC_LIMIT = 512;

    /** @var list<string> */
    private const array NOISY_BASENAME_DIRS = [
        '.git',
        'vendor',
        'node_modules',
        'var',
    ];

    /** @var list<string>|null */
    private readonly ?array $excludeDirs;

    /** Cleanup-tracked temp path for atomic-write safety. */
    private ?string $tmpPath = null;

    /**
     * @param string            $cwd         Project root to scan
     * @param string            $indexPath   Target JSONL path
     * @param LoggerInterface   $logger      Logger for diagnostic events (autowired by DI)
     * @param LockFactory       $lockFactory Lock factory for build exclusion (autowired by DI)
     * @param list<string>|null $excludeDirs Directories to exclude (replaces built-in defaults when provided)
     */
    public function __construct(
        private readonly string $cwd,
        private readonly string $indexPath,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
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

        $handle = @fopen($tmpPath, 'wb');
        if (false === $handle) {
            throw new \RuntimeException("Cannot open temp index file for writing: {$tmpPath}");
        }

        try {
            $count = 0;

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
        // Parent-ignored scratch CWDs (e.g. var/tmp/test-*) must use Finder
        // so their own files are indexed; Git would otherwise report empty.
        if ($this->isInsideGitWorkTree() && !$this->isCwdVcsIgnored()) {
            yield from $this->scanGitEntries();

            return;
        }

        yield from $this->scanFinderEntries();
    }

    /**
     * Stream Git's non-ignored tracked + untracked files and infer directories.
     *
     * @return \Generator<FileMentionIndexEntryDTO>
     */
    private function scanGitEntries(): \Generator
    {
        $process = new Process([
            'git',
            '-C',
            $this->cwd,
            'ls-files',
            '--cached',
            '--others',
            '--exclude-standard',
            '-z',
        ]);

        try {
            $process->start();
        } catch (ProcessStartFailedException $e) {
            throw new \RuntimeException(\sprintf('Failed to start git ls-files for file mention index (cwd=%s).', $this->cwd), 0, $e);
        }

        $emittedDirs = [];
        $count = 0;
        $carry = '';
        $capturedStderr = '';

        try {
            foreach ($process->getIterator() as $type => $buffer) {
                if ('' === $buffer) {
                    continue;
                }

                if (Process::ERR === $type) {
                    $this->appendBoundedDiagnostic($capturedStderr, $buffer);

                    continue;
                }

                if (Process::OUT !== $type) {
                    continue;
                }

                $carry .= $buffer;
                while (false !== ($nulPos = strpos($carry, "\0"))) {
                    $path = substr($carry, 0, $nulPos);
                    $carry = substr($carry, $nulPos + 1);

                    if ('' === $path) {
                        continue;
                    }

                    $path = str_replace('\\', '/', $path);
                    if (str_starts_with($path, './')) {
                        $path = substr($path, 2);
                    }

                    if ($this->isNoisyRelativePath($path)) {
                        continue;
                    }

                    foreach ($this->parentDirectoryPaths($path) as $dirPath) {
                        if (isset($emittedDirs[$dirPath])) {
                            continue;
                        }

                        if ($this->isNoisyRelativePath($dirPath)) {
                            continue;
                        }

                        if ($count >= self::MAX_ENTRIES) {
                            return;
                        }

                        $emittedDirs[$dirPath] = true;
                        yield new FileMentionIndexEntryDTO(
                            path: $dirPath,
                            isDirectory: true,
                        );
                        ++$count;
                    }

                    if ($count >= self::MAX_ENTRIES) {
                        return;
                    }

                    yield new FileMentionIndexEntryDTO(
                        path: $path,
                        isDirectory: false,
                    );
                    ++$count;
                }
            }

            $exitCode = $process->getExitCode();
            if (0 !== $exitCode) {
                throw new \RuntimeException(\sprintf('git ls-files failed while building file mention index (cwd=%s, exit_code=%d, error_output=%s).', $this->cwd, $exitCode ?? -1, trim($capturedStderr)));
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    /**
     * Bounded Finder fallback for non-Git roots / parent-ignored CWDs.
     *
     * @return \Generator<FileMentionIndexEntryDTO>
     */
    private function scanFinderEntries(): \Generator
    {
        $excludeDirs = $this->excludeDirs ?? self::defaultExcludeDirs();

        $finder = Finder::create()
            ->in($this->cwd)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs(true)
            ->ignoreDotFiles(false)
            ->exclude($excludeDirs)
            // Pre-prune noisy trees before RecursiveIteratorIterator descent.
            // ignoreVCSIgnored only filters after traversal and must not be
            // the sole protection against huge generated trees.
            ->filter(
                function (\SplFileInfo $info): bool {
                    $relative = $info instanceof FinderSplFileInfo
                        ? $info->getRelativePathname()
                        : $info->getFilename();
                    $relative = str_replace('\\', '/', $relative);

                    return !$this->isNoisyRelativePath($relative);
                },
                prune: true,
            )
        ;

        // ignoreVCSIgnored(true) is best-effort output filtering only.
        // When the CWD itself is VCS-ignored by a parent repository,
        // skip it so the entire tree is not empty.
        if (!$this->isCwdVcsIgnored()) {
            try {
                $finder->ignoreVCSIgnored(true);
            } catch (\Throwable $e) {
                // Intentional local degradation: .gitignore-aware filtering
                // failed — fall back to explicit excludes only.
                $this->logger->info(
                    'File mention index: ignoreVCSIgnored unavailable, falling back to explicit excludes.',
                    [
                        'component' => 'file_mention_index',
                        'event_type' => 'file_mention_index.vcs_ignored_unavailable',
                        'message' => $e->getMessage(),
                    ],
                );
            }
        } else {
            $this->logger->info(
                'File mention index: CWD is itself VCS-ignored, skipping ignoreVCSIgnored to avoid excluding all files; explicit excludes handle noisy dirs.',
                [
                    'component' => 'file_mention_index',
                    'event_type' => 'file_mention_index.cwd_vcs_ignored',
                    'cwd' => $this->cwd,
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

            if ($this->isNoisyRelativePath($relativePath)) {
                continue;
            }

            yield new FileMentionIndexEntryDTO(
                path: $relativePath,
                isDirectory: $splFileInfo->isDir(),
            );
        }
    }

    /**
     * True when path should never appear in the mention index.
     *
     * Matches whole path components only — `src/vendorish.php` is kept;
     * `tools/phar/vendor/pkg/x.php` is dropped.
     */
    private function isNoisyRelativePath(string $relativePath): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = trim($relativePath, '/');
        if ('' === $relativePath) {
            return false;
        }

        $parts = explode('/', $relativePath);
        $count = \count($parts);

        for ($i = 0; $i < $count; ++$i) {
            $part = $parts[$i];
            if ('' === $part) {
                continue;
            }

            if (\in_array($part, self::NOISY_BASENAME_DIRS, true)) {
                return true;
            }

            if ('.hatfield' === $part && ($i + 1) < $count) {
                $next = $parts[$i + 1];
                if ('sessions' === $next || 'tmp' === $next || 'cache' === $next || str_starts_with($next, 'cache-')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function parentDirectoryPaths(string $filePath): array
    {
        $filePath = str_replace('\\', '/', $filePath);
        $parent = \dirname($filePath);
        if ('.' === $parent || '' === $parent) {
            return [];
        }

        $segments = explode('/', $parent);
        $dirs = [];
        $accum = '';
        foreach ($segments as $segment) {
            if ('' === $segment) {
                continue;
            }
            $accum = '' === $accum ? $segment : $accum.'/'.$segment;
            $dirs[] = $accum;
        }

        return $dirs;
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
     * Basename / relative excludes for Finder fallback only.
     * Git enumeration applies {@see isNoisyRelativePath()} instead.
     *
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

    /**
     * True when cwd is inside a Git worktree (including linked worktrees
     * where `.git` is a file). False when Git is missing or cwd is not a worktree.
     */
    private function isInsideGitWorkTree(): bool
    {
        $process = new Process([
            'git',
            '-C',
            $this->cwd,
            'rev-parse',
            '--is-inside-work-tree',
        ]);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return 0 === $process->getExitCode()
            && 'true' === trim($process->getOutput());
    }

    /**
     * Check whether the CWD is itself ignored by a parent git repository's
     * .gitignore rules.
     *
     * When true, Git enumeration would yield nothing useful for the CWD,
     * and Finder's ignoreVCSIgnored would exclude every file. Callers fall
     * back to explicit-exclude Finder indexing of the CWD's own tree.
     *
     * Uses argv Process (`git -C <cwd> check-ignore .`) which exits 0 when
     * the path matches any gitignore rule.
     */
    private function isCwdVcsIgnored(): bool
    {
        $process = new Process([
            'git',
            '-C',
            $this->cwd,
            'check-ignore',
            '.',
        ]);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        // git check-ignore exits 0 when the path IS ignored.
        return 0 === $process->getExitCode();
    }

    private function appendBoundedDiagnostic(string &$buffer, string $chunk): void
    {
        if ('' === $chunk) {
            return;
        }

        if (str_ends_with($buffer, '…')) {
            return;
        }

        $remaining = self::STDERR_DIAGNOSTIC_LIMIT - \strlen($buffer);
        if ($remaining <= 0) {
            $buffer .= '…';

            return;
        }

        if (\strlen($chunk) <= $remaining) {
            $buffer .= $chunk;

            return;
        }

        $buffer .= substr($chunk, 0, $remaining).'…';
    }
}
