<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\OutputCapConfig;

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
    ) {
    }

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
    public function capIfNeeded(string $text, ?string $path = null): ?OutputCapResult
    {
        $this->maybeCleanup();

        $cap = $this->resolveCap($path);
        $charCount = u($text)->length();

        if ($charCount <= $cap) {
            return null;
        }

        $savedPath = $this->persist($text);

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
    public function persist(string $text): string
    {
        $this->maybeCleanup();

        $this->ensureStorageDirExists();

        $filename = $this->buildFilename();
        $filePath = $this->config->storageDir.'/'.$filename;

        $written = @file_put_contents($filePath, $text, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write output cap file: %s', $filePath));
        }

        return $filePath;
    }

    /**
     * Delete stored files older than the configured retention period.
     *
     * Called automatically on first use, but exposed publicly so session
     * hooks or scheduled tasks can trigger it explicitly.
     */
    public function cleanup(): void
    {
        $dir = $this->config->storageDir;

        if (!is_dir($dir)) {
            return;
        }

        $cutoff = time() - $this->config->retentionSeconds;

        $handle = opendir($dir);
        if (false === $handle) {
            return;
        }

        while (($entry = readdir($handle)) !== false) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $filePath = $dir.'/'.$entry;

            if (is_file($filePath) && filemtime($filePath) < $cutoff) {
                @unlink($filePath);
            }
        }

        closedir($handle);
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

    /**
     * Ensure the storage directory exists with restrictive permissions.
     *
     * @throws \RuntimeException when the directory cannot be created
     */
    private function ensureStorageDirExists(): void
    {
        if (is_dir($this->config->storageDir)) {
            return;
        }

        if (!@mkdir($this->config->storageDir, 0750, true) && !is_dir($this->config->storageDir)) {
            throw new \RuntimeException(\sprintf('Failed to create output cap storage directory: %s', $this->config->storageDir));
        }
    }

    /**
     * Build a unique filename for persisted output.
     *
     * Format: [session_prefix|Ymd]-[16-random-hex].txt
     */
    private function buildFilename(): string
    {
        $prefix = $this->config->sessionPrefix ?? date('Ymd');

        return $prefix.'-'.bin2hex(random_bytes(8)).'.txt';
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
