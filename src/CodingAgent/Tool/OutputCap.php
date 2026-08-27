<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\OutputCapConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Psr\Log\NullLogger;

use function Symfony\Component\String\u;

/**
 * Reusable output capping and persistence for text-producing tools.
 *
 * Applies a configurable character limit to tool output. Oversized text is
 * persisted to disk under a configurable storage directory and replaced with
 * a model-facing notice containing the saved path and inspection hints.
 *
 * Settings (defaults, storage path, caps, retention) hydrate from Hatfield
 * config via {@see OutputCapConfig} which is injected through DI.
 *
 * Also owns path/category classification and read-specific notice construction
 * shared by {@see OutputCapToolResultProcessor} and {@see OutputCapLlmTransformHook}.
 *
 * @see .pi/plans/toolbox-design-plan.md § "Output capping (OutputCap)"
 */
final class OutputCap
{
    /**
     * File extensions treated as "doc-like" (higher cap).
     */
    private const DOC_EXTENSIONS = ['md', 'txt', 'toon'];

    /**
     * Conventional tool argument keys used to determine path-specific caps.
     *
     * @var list<string>
     */
    private const array PATH_ARGUMENT_KEYS = ['path', 'file_path', 'file'];

    /**
     * Tools whose successful result is a dense document-style report/handoff.
     *
     * @var list<string>
     */
    private const array DOCUMENT_REPORT_TOOL_NAMES = ['fork', 'subagent', 'agent_resume', 'agent_retrieve'];

    private bool $cleanedUp = false;

    /**
     * @param OutputCapConfig $config Resolved cap settings from Hatfield config.
     *                                Production code always receives this from
     *                                DI. Tests construct OutputCapConfig directly.
     */
    public function __construct(
        private readonly OutputCapConfig $config,
        ?LockFactory $lockFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->lockFactory = $lockFactory ?? new LockFactory(new FlockStore(sys_get_temp_dir()));
        $this->logger = $logger ?? new NullLogger();
    }

    private readonly LockFactory $lockFactory;
    private readonly LoggerInterface $logger;

    /**
     * Apply output capping and return a structured result or null.
     *
     * When the text fits within the applicable cap it returns null.
     * Otherwise the full text is persisted and an OutputCapResult with
     * the exact model-facing notice text and metrics is returned.
     *
     * @param string      $text the raw tool output
     * @param string|null $path Optional file path used to determine doc vs.
     *                          code cap. Null paths use the default cap.
     *
     * @return OutputCapResult|null structured result when capped, null otherwise
     */
    public function capIfNeeded(string $text, ?string $path = null, ?string $runId = null): ?OutputCapResult
    {
        $this->maybeCleanup();

        $cap = $this->resolveCap($path);
        $charCount = u($text)->length();

        if ($charCount <= $cap) {
            return null;
        }

        if (null === $runId || '' === $runId) {
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
     * Resolve the path used for cap selection.
     *
     * Preference order:
     * 1. Explicit filesystem path-like tool argument (read/write file context),
     *    except native settings which uses dotted keys that must never be
     *    treated as filesystem paths even when they end in .md/.txt/.toon.
     * 2. Synthetic .md path for successful hatfield_docs read (not list).
     * 3. Synthetic .md path for successful document-report tools
     *    (fork/subagent/agent_resume/agent_retrieve) so resolveCap applies docCap without
     *    changing defaultCap.
     * 4. null → defaultCap.
     *
     * Error results from report/docs tools keep defaultCap (null path): failed
     * envelopes are short status text, not handoff documents.
     *
     * @param array<string, mixed> $arguments
     */
    public function resolveCapPath(
        ?string $toolName,
        array $arguments,
        bool $isError = false,
    ): ?string {
        if ('settings' === $toolName) {
            // settings.path is a dotted config key (e.g. docs.foo.md), never a file path.
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
            // Only successful document reads are doc-like; list stays defaultCap.
            return ('read' === ($arguments['operation'] ?? null))
                ? 'hatfield-docs-read.md'
                : null;
        }

        if (null !== $toolName && \in_array($toolName, self::DOCUMENT_REPORT_TOOL_NAMES, true)) {
            // Virtual doc path: only used for extension-based docCap selection.
            return 'handoff-report.md';
        }

        return null;
    }

    /**
     * Build a context-aware capping notice.
     *
     * For read tools: guides follow-up reads to the original file path with
     * offset+limit, avoiding double line numbers from reading the saved
     * rendered artifact.  For all other tools: uses the generic saved-output
     * inspection notice from {@see buildCappedNotice()}.
     *
     * @param array<string, mixed> $arguments
     */
    public function buildContextualNotice(?string $toolName, array $arguments, OutputCapResult $capResult): string
    {
        if ('read' !== $toolName) {
            return $capResult->noticeText;
        }

        $originalPath = $this->extractPathFromArguments($arguments);

        // Only produce read-specific notice when we have the original path.
        // Without it, fall back to the generic saved-artifact notice (head/grep).
        if (null === $originalPath) {
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
     * Persist full text to disk unconditionally.
     *
     * Useful when a consumer (e.g. bash tool) always wants full output
     * saved regardless of whether it exceeds the cap.
     *
     * Stale-file cleanup runs once on first call, matching capIfNeeded()
     * behaviour.
     *
     * @param string $text the text to persist
     *
     * @return string absolute path to the saved file
     *
     * @throws \RuntimeException when the storage directory cannot be
     *                           created or the file cannot be written
     */
    public function persist(string $text, string $runId): string
    {
        $this->maybeCleanup();
        $scope = $this->scopePath($runId);
        $lock = $this->lockFactory->createLock('output-cap:'.hash('sha256', $scope));
        $lock->acquire(true);
        try {
            $this->ensureScopeDirectory($scope);
            $filePath = $scope.'/'.bin2hex(random_bytes(16)).'.txt';
            if (false === @file_put_contents($filePath, $text, \LOCK_EX)) {
                throw new \RuntimeException('Failed to write output cap file.');
            }

            return $filePath;
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete stored files older than the configured retention period.
     *
     * Called automatically on first use, but exposed publicly so session
     * hooks or scheduled tasks can trigger it explicitly.
     */
    public function cleanup(): void
    {
        $root = realpath($this->config->storageDir);
        if (false === $root || !is_dir($root)) {
            return;
        }
        $cutoff = time() - $this->config->retentionSeconds;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || $entry->isLink() || $entry->getMTime() >= $cutoff) {
                continue;
            }
            $name = $entry->getFilename();
            if ($entry->isFile() && preg_match('/^\d{8}-[a-f0-9]{16}\.txt$/', $name)) {
                @unlink($entry->getPathname());
                continue;
            }
            if ($entry->isDir() && preg_match('/^run-[a-f0-9]{64}$/', $name)) {
                $lock = $this->lockFactory->createLock('output-cap:'.hash('sha256', $entry->getPathname()));
                if ($lock->acquire(false)) {
                    try { $this->removeOwnedScope($entry->getPathname()); } finally { $lock->release(); }
                }
            }
        }
    }

    /** Remove one known run scope; missing scopes are success. */
    public function cleanupRun(string $runId, string $phase): void
    {
        $scope = $this->scopePath($runId);
        $files = 0;
        $bytes = 0;
        $lock = $this->lockFactory->createLock('output-cap:'.hash('sha256', $scope));
        $lock->acquire(true);
        try {
            [$files, $bytes] = $this->removeOwnedScope($scope);
            $this->logger->info('output_cap.session_cleanup_completed', ['component' => 'tool.output_cap', 'event_type' => 'output_cap.session_cleanup_completed', 'lifecycle_phase' => $phase, 'removed_file_count' => $files, 'removed_bytes' => $bytes]);
        } catch (\Throwable $exception) {
            $this->logger->warning('output_cap.session_cleanup_failed', ['component' => 'tool.output_cap', 'event_type' => 'output_cap.session_cleanup_failed', 'lifecycle_phase' => $phase, 'exception_class' => $exception::class]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Find a file-path value from tool call arguments.
     *
     * Checks known path-carrying argument keys and returns the first
     * string value found.  Returns null when no path argument exists.
     *
     * @param array<string, mixed> $arguments
     */
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
     * Run cleanup once on first use (capIfNeeded() or persist()).
     *
     * Chose first-use invocation over constructor because cleanup is an
     * I/O operation that should not happen during container/DI wiring.
     */
    private function maybeCleanup(): void
    {
        if ($this->cleanedUp) {
            return;
        }

        $this->cleanedUp = true;
        $this->cleanup();
    }

    private function scopePath(string $runId): string
    {
        return rtrim($this->config->storageDir, '/').'/run-'.hash('sha256', $runId);
    }

    private function ensureScopeDirectory(string $scope): void
    {
        $root = $this->config->storageDir;
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new \RuntimeException('Failed to create output cap storage directory.');
        }
        $canonicalRoot = realpath($root);
        if (false === $canonicalRoot || is_link($scope)) {
            throw new \RuntimeException('Refusing unsafe output cap scope.');
        }
        if (!is_dir($scope) && !@mkdir($scope, 0750) && !is_dir($scope)) {
            throw new \RuntimeException('Failed to create output cap run scope.');
        }
        $canonicalScope = realpath($scope);
        if (false === $canonicalScope || dirname($canonicalScope) !== $canonicalRoot) {
            throw new \RuntimeException('Refusing output cap scope outside configured root.');
        }
    }

    /** @return array{0: int, 1: int} */
    private function removeOwnedScope(string $scope): array
    {
        if (!file_exists($scope) && !is_link($scope)) {
            return [0, 0];
        }
        if (is_link($scope)) {
            @unlink($scope);
            return [0, 0];
        }
        $root = realpath($this->config->storageDir);
        $canonicalScope = realpath($scope);
        if (false === $root || false === $canonicalScope || dirname($canonicalScope) !== $root) {
            throw new \RuntimeException('Refusing unsafe output cap cleanup scope.');
        }
        $files = 0;
        $bytes = 0;
        foreach (new \DirectoryIterator($canonicalScope) as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
                if (@unlink($entry->getPathname())) { ++$files; }
                continue;
            }
            if ($entry->isDir()) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($entry->getPathname(), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $child) {
                    if ($child->isLink()) { continue; }
                    if ($child->isFile()) { $bytes += $child->getSize(); if (@unlink($child->getPathname())) { ++$files; } }
                    elseif ($child->isDir()) { @rmdir($child->getPathname()); }
                }
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($canonicalScope);
        return [$files, $bytes];
    }

    /**
     * Determine which character cap applies based on file extension.
     *
     * Doc-like extensions (.md, .txt, .toon) use docCap.
     * Everything else uses defaultCap.
     * Null paths use defaultCap.
     */
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

    /**
     * Build a model-facing notice about capped output.
     *
     * Generic fallback for non-read tools.  Suggests inspecting the saved
     * output artefact with read (offset+limit) for chunked inspection and
     * grep for targeted search.  Read-tool callers should use
     * {@see buildContextualNotice()} that points follow-up reads at the
     * original file, not this artefact.
     */
    private function buildCappedNotice(string $fullText, int $cap, string $savedPath): string
    {
        $charCount = u($fullText)->length();
        $tokenEstimate = (int) ceil($charCount / 4);
        $escapedGrepPath = escapeshellarg($savedPath);
        $jsonPath = json_encode($savedPath, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        return \sprintf(
            "[Output capped: %d chars (~%d tokens) > %d-char cap]\n".
            "Saved full output: %s\n".
            "\n".
            "Next: inspect the saved output, e.g.\n".
            "- read(path: %s, offset: 1, limit: 200)\n".
            "- bash(command: \"grep -n -- 'PATTERN' %s | head -50\")\n".
            'Do not rerun the original command or read the saved output without offset+limit.',
            $charCount, $tokenEstimate, $cap, $savedPath, $jsonPath, $escapedGrepPath,
        );
    }
}
