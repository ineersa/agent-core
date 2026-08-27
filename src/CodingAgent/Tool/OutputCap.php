<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\OutputCapConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

use function Symfony\Component\String\u;

/**
 * Reusable output capping and persistence for text-producing tools.
 *
 * Oversized output is ephemeral runtime material. It is persisted only below
 * the explicit owning run's hashed scope, never in canonical session history.
 */
final class OutputCap
{
    /** File extensions whose dense, document-like output receives the higher cap. */
    private const DOC_EXTENSIONS = ['md', 'txt', 'toon'];

    /**
     * Conventional tool argument keys that identify a filesystem path for cap selection.
     * The first non-empty string wins because tools may expose more than one alias.
     *
     * @var list<string>
     */
    private const array PATH_ARGUMENT_KEYS = ['path', 'file_path', 'file'];

    /**
     * Successful results from these tools are handoff/report documents even without a
     * filesystem path, so they use a synthetic doc-like path for cap selection only.
     *
     * @var list<string>
     */
    private const array DOCUMENT_REPORT_TOOL_NAMES = ['fork', 'subagent', 'agent_resume', 'agent_retrieve'];

    private bool $cleanedUp = false;

    public function __construct(
        private readonly OutputCapConfig $config,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns null when text fits the applicable cap; otherwise persists the full text
     * in the owning run scope and returns the model-facing replacement notice.
     *
     * The optional path chooses the document/default cap. A run ID is required only
     * when persistence is needed, so uncapped output remains side-effect free.
     *
     * @return OutputCapResult|null structured result when capped
     */
    public function capIfNeeded(string $text, ?string $runId, ?string $path = null): ?OutputCapResult
    {
        $this->maybeCleanup();

        $cap = $this->resolveCap($path);
        $charCount = u($text)->length();
        if ($charCount <= $cap) {
            return null;
        }

        if (null === $runId || '' === trim($runId)) {
            throw new \LogicException('OutputCap requires a run ID when persisting oversized output.');
        }

        $savedPath = $this->persist($text, $runId);

        return new OutputCapResult(
            savedPath: $savedPath,
            cap: $cap,
            charCount: $charCount,
            tokenEstimate: (int) ceil($charCount / 4),
            noticeText: $this->buildCappedNotice($text, $cap, $savedPath),
        );
    }

    /**
     * Cap-selection precedence: a real path argument, then synthetic paths for
     * successful document outputs, otherwise the default cap. Settings' dotted
     * `path` key is configuration, not a filesystem path; errors intentionally stay
     * at the default cap because they are status envelopes rather than documents.
     * Synthetic paths affect cap selection only and are never persisted or exposed.
     *
     * @param array<string, mixed> $arguments
     */
    public function resolveCapPath(?string $toolName, array $arguments, bool $isError = false): ?string
    {
        if ('settings' === $toolName) {
            return null;
        }

        $path = $this->extractPathFromArguments($arguments);
        if (null !== $path) {
            return $path;
        }

        if ($isError) {
            return null;
        }

        if ('hatfield_docs' === $toolName) {
            return ('read' === ($arguments['operation'] ?? null)) ? 'hatfield-docs-read.md' : null;
        }

        return null !== $toolName && \in_array($toolName, self::DOCUMENT_REPORT_TOOL_NAMES, true)
            ? 'handoff-report.md'
            : null;
    }

    /**
     * Read follow-ups target the original file, not the rendered saved artifact, so
     * offset/limit and grep remain meaningful without duplicating rendered output.
     * Without an original path, fall back to the generic saved-artifact guidance.
     *
     * @param array<string, mixed> $arguments
     */
    public function buildContextualNotice(?string $toolName, array $arguments, OutputCapResult $capResult): string
    {
        if ('read' !== $toolName || null === ($originalPath = $this->extractPathFromArguments($arguments))) {
            return $capResult->noticeText;
        }

        $originalOffset = $arguments['offset'] ?? null;
        $offset = (\is_int($originalOffset) && $originalOffset > 0) ? $originalOffset : 1;
        $escapedGrepPath = escapeshellarg($originalPath);

        return <<<STRING
[Output capped: {$capResult->charCount} chars (~{$capResult->tokenEstimate} tokens) > {$capResult->cap}-char cap]
Saved full output: {$capResult->savedPath}

Next: use a focused follow-up, e.g.
- read(path: "{$originalPath}", offset: {$offset}, limit: 200)
- bash(command: "grep -n -- 'PATTERN' {$escapedGrepPath} | head -50")
Do not repeat the original full read or read the saved output with read.
STRING;
    }

    /**
     * Persist full text unconditionally in the hashed scope owned by the run.
     *
     * The first-use stale fallback runs before persistence, while lifecycle hooks own
     * normal cleanup. Throws when safe scope creation or writing fails.
     *
     * @return string absolute path to the saved file
     */
    public function persist(string $text, string $runId): string
    {
        $this->maybeCleanup();
        $canonicalRoot = $this->ensureStorageDirectory();
        $scopeName = $this->scopeName($runId);
        $lock = $this->scopeLock($canonicalRoot, $scopeName);
        $lock->acquire(true);

        try {
            $canonicalScope = $this->ensureScopeDirectory($canonicalRoot, $scopeName);
            $filePath = $canonicalScope.'/'.bin2hex(random_bytes(16)).'.txt';
            if (false === @file_put_contents($filePath, $text, \LOCK_EX)) {
                throw new \RuntimeException('Failed to write output cap file.');
            }

            return $filePath;
        } finally {
            $lock->release();
        }
    }

    /**
     * First-use crash/orphan fallback. It only considers exact legacy files and
     * exact hashed run scopes, never unknown root entries or symlinks.
     */
    public function cleanup(): void
    {
        $root = realpath($this->config->storageDir);
        if (false === $root || !is_dir($root)) {
            return;
        }

        $cutoff = time() - $this->config->retentionSeconds;
        try {
            foreach (new \DirectoryIterator($root) as $entry) {
                if ($entry->isDot() || $entry->isLink() || $entry->getMTime() >= $cutoff) {
                    continue;
                }

                $name = $entry->getFilename();
                if ($entry->isFile() && 1 === preg_match('/^\d{8}-[a-f0-9]{16}\.txt$/', $name)) {
                    if (!@unlink($entry->getPathname())) {
                        $this->logCleanupFailure('stale_fallback', new \RuntimeException('Failed to remove stale legacy output cap artifact.'));
                    }

                    continue;
                }

                if (!$entry->isDir() || 1 !== preg_match('/^run-[a-f0-9]{64}$/', $name)) {
                    continue;
                }

                $lock = $this->scopeLock($root, $name);
                if (!$lock->acquire(false)) {
                    continue;
                }

                try {
                    $this->removeOwnedScope($entry->getPathname());
                } catch (\Throwable $exception) {
                    $this->logCleanupFailure('stale_fallback', $exception);
                } finally {
                    $lock->release();
                }
            }
        } catch (\Throwable $exception) {
            $this->logCleanupFailure('stale_fallback', $exception);
        }
    }

    /**
     * Idempotently remove the exact scope owned by one run. Failures are
     * intentionally contained and logged so controller shutdown can release
     * ownership while a future start retries the artifact cleanup.
     */
    public function cleanupRun(string $runId, string $phase): void
    {
        $canonicalRoot = $this->canonicalStorageRoot();
        if (null === $canonicalRoot) {
            $this->logCleanupCompleted($phase, 0, 0);

            return;
        }

        $scopeName = $this->scopeName($runId);
        $lock = $this->scopeLock($canonicalRoot, $scopeName);
        $lock->acquire(true);

        try {
            [$files, $bytes] = $this->removeOwnedScope($canonicalRoot.'/'.$scopeName);
            $this->logCleanupCompleted($phase, $files, $bytes);
        } catch (\Throwable $exception) {
            $this->logCleanupFailure($phase, $exception);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $arguments */
    private function extractPathFromArguments(array $arguments): ?string
    {
        foreach (self::PATH_ARGUMENT_KEYS as $key) {
            $value = $arguments[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Run the 24-hour stale fallback once on first use rather than during container
     * construction, because cleanup performs filesystem I/O.
     */
    private function maybeCleanup(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;
        $this->cleanup();
    }

    private function scopeName(string $runId): string
    {
        return 'run-'.hash('sha256', $runId);
    }

    private function scopeLock(string $canonicalRoot, string $scopeName): \Symfony\Component\Lock\LockInterface
    {
        return $this->lockFactory->createLock('output-cap:'.hash('sha256', $canonicalRoot.'/'.$scopeName));
    }

    private function ensureStorageDirectory(): string
    {
        $root = $this->config->storageDir;
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new \RuntimeException('Failed to create output cap storage directory.');
        }

        $canonicalRoot = $this->canonicalStorageRoot();
        if (null === $canonicalRoot) {
            throw new \RuntimeException('Refusing unsafe output cap storage directory.');
        }

        return $canonicalRoot;
    }

    private function canonicalStorageRoot(): ?string
    {
        $canonicalRoot = realpath($this->config->storageDir);

        return false !== $canonicalRoot && is_dir($canonicalRoot) ? $canonicalRoot : null;
    }

    /** @return string canonical scope directory */
    private function ensureScopeDirectory(string $canonicalRoot, string $scopeName): string
    {
        $scope = $canonicalRoot.'/'.$scopeName;
        if (is_link($scope)) {
            throw new \RuntimeException('Refusing unsafe output cap scope.');
        }
        if (file_exists($scope) && !is_dir($scope)) {
            throw new \RuntimeException('Refusing non-directory output cap scope.');
        }
        if (!is_dir($scope) && !@mkdir($scope, 0750) && !is_dir($scope)) {
            throw new \RuntimeException('Failed to create output cap run scope.');
        }

        $canonicalScope = realpath($scope);
        if (false === $canonicalScope || \dirname($canonicalScope) !== $canonicalRoot) {
            throw new \RuntimeException('Refusing output cap scope outside configured root.');
        }

        return $canonicalScope;
    }

    /** @return array{0: int, 1: int} removed file count and bytes */
    private function removeOwnedScope(string $scope): array
    {
        if (!file_exists($scope) && !is_link($scope)) {
            return [0, 0];
        }
        if (is_link($scope)) {
            if (!@unlink($scope)) {
                throw new \RuntimeException('Failed to remove output cap scope symlink.');
            }

            return [1, 0];
        }
        if (!is_dir($scope)) {
            throw new \RuntimeException('Refusing non-directory output cap cleanup scope.');
        }

        $canonicalRoot = realpath($this->config->storageDir);
        $canonicalScope = realpath($scope);
        if (false === $canonicalRoot || false === $canonicalScope || \dirname($canonicalScope) !== $canonicalRoot) {
            throw new \RuntimeException('Refusing unsafe output cap cleanup scope.');
        }

        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($canonicalScope, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink()) {
                if (!@unlink($path)) {
                    throw new \RuntimeException('Failed to remove output cap symlink occupant.');
                }
                ++$files;

                continue;
            }
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
                if (!@unlink($path)) {
                    throw new \RuntimeException('Failed to remove output cap artifact.');
                }
                ++$files;

                continue;
            }
            if ($entry->isDir() && !@rmdir($path)) {
                throw new \RuntimeException('Failed to remove output cap nested directory.');
            }
        }

        if (!@rmdir($canonicalScope)) {
            throw new \RuntimeException('Failed to remove output cap scope directory.');
        }

        return [$files, $bytes];
    }

    private function resolveCap(?string $path): int
    {
        if (null === $path) {
            return $this->config->defaultCap;
        }

        $lowerPath = strtolower($path);
        foreach (self::DOC_EXTENSIONS as $ext) {
            if (str_ends_with($lowerPath, '.'.$ext)) {
                return $this->config->docCap;
            }
        }

        return $this->config->defaultCap;
    }

    private function logCleanupCompleted(string $phase, int $files, int $bytes): void
    {
        $this->logger->info('output_cap.session_cleanup_completed', [
            'component' => 'tool.output_cap',
            'event_type' => 'output_cap.session_cleanup_completed',
            'lifecycle_phase' => $phase,
            'removed_file_count' => $files,
            'removed_bytes' => $bytes,
        ]);
    }

    private function logCleanupFailure(string $phase, \Throwable $exception): void
    {
        $this->logger->warning('output_cap.session_cleanup_failed', [
            'component' => 'tool.output_cap',
            'event_type' => 'output_cap.session_cleanup_failed',
            'lifecycle_phase' => $phase,
            'failure_kind' => 'operation_exception',
            'exception_class' => $exception::class,
        ]);
    }

    /**
     * Generic guidance for non-read tools. Read callers use buildContextualNotice()
     * to direct follow-ups to their original file instead of this saved artifact.
     */
    private function buildCappedNotice(string $fullText, int $cap, string $savedPath): string
    {
        $charCount = u($fullText)->length();
        $tokenEstimate = (int) ceil($charCount / 4);
        $escapedGrepPath = escapeshellarg($savedPath);
        $jsonPath = json_encode($savedPath, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return \sprintf(
            "[Output capped: %d chars (~%d tokens) > %d-char cap]\n".
            "Saved full output: %s\n\n".
            "Next: inspect the saved output, e.g.\n".
            "- read(path: %s, offset: 1, limit: 200)\n".
            "- bash(command: \"grep -n -- 'PATTERN' %s | head -50\")\n".
            'Do not rerun the original command or read the saved output without offset+limit.',
            $charCount, $tokenEstimate, $cap, $savedPath, $jsonPath, $escapedGrepPath,
        );
    }
}
