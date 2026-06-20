<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Path\PathResolver;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

/**
 * Edit an existing file by applying a unified diff patch.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * Uses a three-phase approach around GNU patch for safety and link preservation:
 * 1. Dry-run validation (--dry-run --posix) — never touches the target.
 * 2. Apply to temp output (patch -o <temp>) — generates patched bytes
 *    without modifying the target.
 * 3. In-place byte write through the original target path — preserves
 *    symlink target semantics and hardlink inode identity.
 *
 * Features:
 * - Whitespace-tolerant matching via patch -l flag.
 * - 3-line fuzz tolerance via patch -F3 flag.
 * - Forward-only application via patch -N flag.
 * - Symfony Lock (flock) around the entire critical section.
 * - Best-effort rollback on write failure: original bytes restored
 *   through the target path in-place (not via rename).
 *
 * Tradeoff: in-place write preserves symlinks and hardlinks but is not
 * crash-atomic like temp-output + rename. Most real usage has git as
 * backup/recovery, and cancellation is only checked between phases, not
 * mid-write (file_put_contents with LOCK_EX completes as a single
 * non-checkpointed PHP call).
 *
 * Cancellation behavior:
 * - Cancellation before the final in-place write leaves the target untouched.
 * - Cancellation cannot interrupt file_put_contents mid-write.
 * - If in-place write fails mid-flight (short write), best-effort rollback
 *   restores original bytes.
 */
final class EditFileTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Execute the edit tool.
     *
     * @param array<string, mixed> $arguments Must contain 'path' (string) and 'patch' (string)
     *
     * @return string Success message with addition/deletion stats, or a no-op message
     *
     * @throws ToolCallException on validation failures, patch failures, or tool-level errors
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    public function __invoke(array $arguments): string
    {
        $this->validateArguments($arguments);

        // Wrap core logic in cancellation checkpoints
        return $this->toolRuntime->run(function () use ($arguments): string {
            $targetPath = $this->resolveAndVerifyTarget($arguments['path']);
            $patchContent = $arguments['patch'];

            $stats = $this->applyPatch($targetPath, $patchContent);

            return $this->formatSuccess($targetPath, $stats['additions'], $stats['deletions']);
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'edit',
            description: 'Apply a unified diff patch to an existing file. The target file must exist; use the write tool for new files.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'File path to edit (absolute, or relative to the working directory)',
                    ],
                    'patch' => [
                        'type' => 'string',
                        'description' => 'Unified diff in standard format',
                    ],
                ],
                'required' => ['path', 'patch'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'edit path patch — apply a unified diff patch to an existing file; file must already exist, use write for new files',
            promptGuidelines: [
                'Read the current file contents with the `read` tool before generating a patch — never guess line numbers or context.',
                'Use exact unchanged context lines from the current file. Do not modify or reformat context lines; they must match byte-for-byte.',
                'Provide the patch in standard unified diff format (diff -u). Use `read` and its `cat -n` original line numbers to determine `@@` hunk header ranges.',
                '`@@` hunk headers must reference the original line numbers shown by `read` via `cat -n` (e.g. `@@ -42,6 +42,8 @@`).',
                'Keep hunks tight: include only enough unchanged context (typically 3–4 lines) for the patch to apply reliably.',
                'The patch may contain multiple hunks to edit different parts of the file.',
                'The target file must already exist — use the write tool to create new files.',
                'Whitespace mismatches between the patch and the target file are handled automatically (tolerant matching).',
                'On success, the tool returns only the file path and addition/deletion stats. Do NOT echo or expect the full applied diff back.',
                'If the patch produces no changes, the tool reports "No changes" without modifying the file.',
                'If an edit fails with a stale-hunk error (context mismatch), the error includes a current-file context window with exact line numbers. Re-read the file with `read` and retry with a regenerated patch using the exact current context from `cat -n`.',
                'If an edit fails with a format error, check that the patch has proper ---/+++ headers and @@ hunk headers in standard unified diff format.',
                'If the target file lacks a trailing newline, the error hint will mention it. Add a trailing newline with the write tool or include "\\ No newline at end of file" markers in the patch.',
            ],
        );
    }

    /**
     * Validate that required arguments exist and have correct types.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws ToolCallException when arguments are missing or invalid
     */
    private function validateArguments(array $arguments): void
    {
        $path = $arguments['path'] ?? null;
        $patch = $arguments['patch'] ?? null;

        if (!\is_string($path) || '' === $path) {
            throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path.');
        }

        if (!\is_string($patch) || '' === $patch) {
            throw new ToolCallException('The "patch" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a unified diff in standard format.');
        }
    }

    /**
     * Resolve the path and verify the target file exists and is readable.
     *
     * @return string Absolute path to the target file
     *
     * @throws ToolCallException when the file does not exist or is not readable
     */
    private function resolveAndVerifyTarget(string $path): string
    {
        $targetPath = PathResolver::resolve($path);

        if (!is_file($targetPath) || !is_readable($targetPath)) {
            throw new ToolCallException(\sprintf('File "%s" does not exist or is not readable.', $targetPath), retryable: false, hint: 'Use the write tool to create new files.');
        }

        return $targetPath;
    }

    /**
     * Orchestrate the full patch lifecycle under a Symfony Lock:
     * snapshot → dry-run → temp-apply → in-place write.
     *
     * The lock key is derived from the real path so that edits to the
     * same file are serialized. The Symfony Lock is advisory and covers
     * same-tool edits through the same symlink target; external writers
     * and other tools are not globally excluded.
     *
     * In-place write preserves symlinks (follows the link to update the
     * target) and hardlinks (writes through the original path so all
     * shared inode names see the update).
     *
     * @return array{additions: int, deletions: int}
     *
     * @throws ToolCallException on patch or infrastructure failures
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    private function applyPatch(string $targetPath, string $patchContent): array
    {
        $patchFile = $this->writePatchFile($patchContent);
        $tempOut = @tempnam(sys_get_temp_dir(), 'hatfield_out_');
        if (false === $tempOut) {
            @unlink($patchFile);
            throw $this->infraError('Failed to create temp output file.', $targetPath);
        }

        // Lock key: derived from real path so symlinks to the same target
        // serialize. Distinct hardlink names do not share a lock key via
        // realpath() (each name resolves to its own path), but the final
        // in-place write serializes them at the write level and link
        // identity is preserved.
        $realPath = false !== ($r = realpath($targetPath)) ? $r : $targetPath;
        $lockKey = 'edit-file-'.hash('sha256', $realPath);
        $lock = $this->lockFactory->createLock($lockKey);

        try {
            $lock->acquire(true); // blocking — consistent with project flock pattern
            // Snapshot original bytes (reads through symlinks to target content)
            $originalContent = @file_get_contents($targetPath);
            if (false === $originalContent) {
                throw $this->infraError('Failed to read original file for snapshot.', $targetPath);
            }

            // ── Phase 1: dry-run ──
            // Retained even though Phase 2 also writes only to temp: the
            // dry-run provides earlier, clearer failure classification
            // (distinguishing stale/malformed/noop before any write phase)
            // and defense-in-depth — no content is generated if validation fails.
            // Use the real (resolved) path for patch commands since GNU patch
            // refuses to operate on symlinks ("is not a regular file").
            $drResult = $this->runPatchDryRun($realPath, $patchFile);

            if (0 !== $drResult->exitCode) {
                $noTrailingNewline = $this->targetLacksTrailingNewline($targetPath);
                $failure = $this->buildFailureMessage(
                    $targetPath,
                    $drResult->stdout, $drResult->stderr,
                    $originalContent, $noTrailingNewline,
                );

                throw new ToolCallException($failure['message'], retryable: $failure['retryable'], hint: $failure['hint']);
            }

            // ── Phase 2: apply to temp output (target untouched) ──
            $applyResult = $this->runPatchApply($realPath, $patchFile, $tempOut);

            if (0 !== $applyResult->exitCode) {
                $noTrailingNewline = $this->targetLacksTrailingNewline($targetPath);
                $failure = $this->buildFailureMessage(
                    $targetPath,
                    $applyResult->stdout, $applyResult->stderr,
                    $originalContent, $noTrailingNewline,
                );

                throw new ToolCallException($failure['message'], retryable: $failure['retryable'], hint: $failure['hint']);
            }

            // ── Phase 3: read patched bytes and no-op check ──
            $patchedContent = @file_get_contents($tempOut);
            if (false === $patchedContent) {
                throw $this->infraError('Failed to read patched output.', $targetPath);
            }

            // Early return if patch produced identical content — target unchanged
            if ($patchedContent === $originalContent) {
                return ['additions' => 0, 'deletions' => 0];
            }

            // ── Phase 4: in-place write through target path ──
            // Writes through $targetPath to preserve symlink target
            // semantics and hardlink inode identity.
            $this->writeBytesInPlace($targetPath, $patchedContent, $originalContent);

            $stats = $this->computeStats($patchContent);

            return [
                'additions' => $stats['additions'],
                'deletions' => $stats['deletions'],
            ];
        } finally {
            // Release the lock (safely — may fail on unacquired or broken locks).
            try {
                $lock->release();
            } catch (\Throwable) {
                // Lock release failure is non-fatal during cleanup.
            }

            // Clean up temp files
            if (is_file($patchFile)) {
                @unlink($patchFile);
            }
            if (is_file($tempOut)) {
                @unlink($tempOut);
            }
        }
    }

    /**
     * Write patched bytes through the target path in-place.
     *
     * This preserves symlinks (writes to the link target) and hardlinks
     * (all names sharing the inode see the update). On write failure or
     * short write, attempts best-effort rollback by restoring original bytes.
     *
     * @param string $targetPath      target path to write through (may be a symlink)
     * @param string $patchedContent  bytes to write
     * @param string $originalContent bytes to restore on failure
     *
     * @throws ToolCallException on write failure
     */
    private function writeBytesInPlace(string $targetPath, string $patchedContent, string $originalContent): void
    {
        $written = @file_put_contents($targetPath, $patchedContent, \LOCK_EX);

        if (false !== $written && $written === \strlen($patchedContent)) {
            return; // Success
        }

        // Best-effort rollback: restore original bytes in-place.
        // This preserves the same symlink/hardlink semantics as the
        // successful path.
        $restored = @file_put_contents($targetPath, $originalContent, \LOCK_EX);
        $rollbackOk = false !== $restored && $restored === \strlen($originalContent);
        $rollbackStatus = $rollbackOk
            ? 'Original content restored.'
            : 'Rollback may be incomplete (write failure or short write) — verify file integrity with `read` or `git diff`.';

        $detail = false === $written
            ? 'write returned false (permission/disk error)'
            : \sprintf('short write (%d of %d bytes)', $written, \strlen($patchedContent));

        throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": %s. %s', $targetPath, $detail, $rollbackStatus), retryable: true, hint: 'Check file permissions and disk space, then retry.');
    }

    /**
     * Build a structured infrastructure/write error.
     */
    private function infraError(string $context, string $targetPath): ToolCallException
    {
        return new ToolCallException(
            \sprintf('[E_PATCH_INFRA] %s for "%s".', $context, $targetPath),
            retryable: true,
            hint: 'Check filesystem availability, permissions, and disk space.',
        );
    }

    /* ── Patch process helpers ── */

    /**
     * Run patch dry-run validation.
     *
     * @return CancellableProcessResult process result; caller inspects exitCode
     *                                  to decide success/failure
     *
     * @throws \RuntimeException on cancellation or timeout
     */
    private function runPatchDryRun(string $targetPath, string $patchFile): CancellableProcessResult
    {
        $process = new Process([
            'patch', '-u', '-F3', '-l', '-N',
            '--dry-run', '--posix',
            '-o', '/dev/null',
            $targetPath, $patchFile,
        ]);

        return $this->toolRuntime->runCancellableProcess($process);
    }

    /**
     * Apply the patch to a temp output file (target file is never touched).
     *
     * @return CancellableProcessResult process result; caller inspects exitCode
     *
     * @throws \RuntimeException on cancellation or timeout
     */
    private function runPatchApply(string $targetPath, string $patchFile, string $tempOut): CancellableProcessResult
    {
        $process = new Process([
            'patch', '-u', '-F3', '-l', '-N',
            '-o', $tempOut,
            $targetPath, $patchFile,
        ]);

        return $this->toolRuntime->runCancellableProcess($process);
    }

    /* ── Failure classification and message building ── */

    /**
     * Build a classified, bounded failure message suitable for LLM consumption.
     *
     * @return array{message: string, retryable: bool, hint: string}
     */
    private function buildFailureMessage(
        string $targetPath,
        string $stdout,
        string $stderr,
        string $originalContent,
        bool $noTrailingNewline,
    ): array {
        $combined = $this->combinedPatchOutput($stdout, $stderr);
        $classification = $this->classifyPatchFailure($combined);
        $code = $classification['code'];
        $retryable = $classification['retryable'];
        $baseHint = $classification['baseHint'];

        $trimmed = $this->trimPatchOutput($combined);
        $phase = 'edit failed';

        // Start building the message
        $message = \sprintf("[%s] %s for \"%s\":\n%s", $code, $phase, $targetPath, $trimmed);

        // For stale hunk failures: include bounded current-file context
        $currentContext = '';
        if ('E_PATCH_STALE' === $code) {
            $failedLines = $this->extractFailedHunkLines($combined);
            if ([] !== $failedLines) {
                $currentContext = $this->buildCurrentFileContext($targetPath, $originalContent, $failedLines);
                if ('' !== $currentContext) {
                    $message .= "\n\nCurrent file context:\n".$currentContext;
                }
            }
        }

        // Build hint
        $hint = $baseHint;

        if ($noTrailingNewline) {
            $nlHint = 'The target file does not end with a newline. Unified diff context lines normally expect newline-terminated text; add a trailing newline with the write tool or include "\\ No newline at end of file" markers in the patch.';

            // Prepend NL hint for stale/malformed to make it the primary actionable guidance
            if ('' !== ($baseHint ?? '')) {
                $hint = $nlHint.' '.$baseHint;
            } else {
                $hint = $nlHint;
            }
        }

        return [
            'message' => trim($message),
            'retryable' => $retryable,
            'hint' => $hint,
        ];
    }

    /**
     * Classify a patch failure from combined stdout+stderr output.
     *
     * @return array{code: string, retryable: bool, baseHint: string}
     */
    private function classifyPatchFailure(string $combinedOutput): array
    {
        // Stale hunk / context mismatch
        if (preg_match('/Hunk\s+#\d+\s+FAILED/i', $combinedOutput)) {
            return [
                'code' => 'E_PATCH_STALE',
                'retryable' => true,
                'baseHint' => 'The patch context does not match the current file content. Read the file with the `read` tool using `cat -n` for exact line numbers, then regenerate the patch with the exact current context lines.',
            ];
        }

        // Malformed / not a unified diff
        if (
            preg_match('/(?:only\s+garbage\s+was\s+found|not\s+a\s+unified\s+diff|malformed|missing\s+header|unrecognized\s+input|can\'t\s+find\s+file\s+to\s+patch)/i', $combinedOutput)
        ) {
            return [
                'code' => 'E_PATCH_FORMAT',
                'retryable' => true,
                'baseHint' => 'The patch appears malformed or is not a valid unified diff. Provide a standard `diff -u` format patch with proper ---/+++ headers and @@ hunk headers. Each hunk must have correct line-number ranges.',
            ];
        }

        // Already applied / reversed / skipped by -N
        if (
            preg_match('/(?:reversed(?:\s+or\s+previously\s+applied)|already\s+applied|skipping\s+patch|patch\s+is\s+reversed|previously\s+applied)/i', $combinedOutput)
        ) {
            return [
                'code' => 'E_PATCH_NOOP',
                'retryable' => true,
                'baseHint' => 'The patch appears to be reversed or already applied. Read the current file to check whether the intended changes are already present.',
            ];
        }

        // Partial success — some hunks applied, some failed or were
        // ignored. GNU patch reports this as "N out of M hunks FAILED"
        // or "ignored". Retained as a defensive branch covering outputs
        // where only some hunks mismatch while others apply cleanly;
        // classified as stale so the model retries with current context.
        if (preg_match('/(\d+)\s+out\s+of\s+(\d+)\s+hunks?\s+(?:FAILED|ignored)/i', $combinedOutput)) {
            return [
                'code' => 'E_PATCH_STALE',
                'retryable' => true,
                'baseHint' => 'Some hunks failed to apply. Read the current file with `read` using `cat -n` for exact line numbers, then regenerate the patch with the exact current context lines for the failing hunks.',
            ];
        }

        // Generic failure — retryable, suggest re-reading
        return [
            'code' => 'E_PATCH_STALE',
            'retryable' => true,
            'baseHint' => 'The patch could not be applied. Read the current file with the `read` tool using `cat -n` for exact line numbers, then regenerate the patch.',
        ];
    }

    /**
     * Extract failed hunk line numbers from GNU patch output.
     *
     * Parses lines like "Hunk #1 FAILED at 42." to extract the line number.
     *
     * @return int[] Line numbers where hunks failed
     */
    private function extractFailedHunkLines(string $combinedOutput): array
    {
        $lines = [];
        $matched = preg_match_all('/Hunk\s+#\d+\s+FAILED\s+at\s+(\d+)/i', $combinedOutput, $matches);

        if (false !== $matched && $matched > 0) {
            foreach ($matches[1] as $lineStr) {
                $lines[] = (int) $lineStr;
            }
        }

        return $lines;
    }

    /**
     * Build a bounded current-file context window around failed line numbers.
     *
     * Returns a compact snippet with line numbers. The failed lines are
     * marked with a "→" prefix. Context extends ±$contextLines around each
     * failed line; overlapping windows are merged. Total output is capped.
     *
     * @param string $targetPath      path to the target file (already verified readable)
     * @param string $originalContent full original file content (bytes)
     * @param int[]  $failedLines     line numbers where hunks failed (1-based)
     * @param int    $contextLines    number of context lines above/below each failed line
     *
     * @return string formatted context window, or empty string on failure
     */
    private function buildCurrentFileContext(
        string $targetPath,
        string $originalContent,
        array $failedLines,
        int $contextLines = 4,
    ): string {
        $fileLines = explode("\n", $originalContent);

        // Normalise CRLF / lone-CR line endings to plain LF for display.
        foreach ($fileLines as &$line) {
            $line = rtrim($line, "\r");
        }
        unset($line);

        // Drop the trailing empty element produced by explode for
        // newline-terminated content — otherwise it becomes a phantom
        // extra line that skews model-visible line counts.
        if ([] !== $fileLines && '' === end($fileLines)) {
            array_pop($fileLines);
        }

        $totalLines = \count($fileLines);

        if (0 === $totalLines || [] === $failedLines) {
            return '';
        }

        // Build inclusive ranges to display
        $ranges = [];
        foreach ($failedLines as $line) {
            $start = max(1, $line - $contextLines);
            $end = min($totalLines, $line + $contextLines);
            $ranges[] = [$start, $end];
        }

        // Merge overlapping ranges
        usort($ranges, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            if ([] === $merged) {
                $merged[] = [$start, $end];

                continue;
            }

            $last = array_key_last($merged);
            if ($start <= $merged[$last][1] + 1) {
                // Overlap or adjacent — merge
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        // Cap total merged context lines to avoid dumping too much
        $maxContextLines = 60;
        $totalInRanges = 0;
        $cappedRanges = [];
        foreach ($merged as [$start, $end]) {
            $size = $end - $start + 1;
            if ($totalInRanges + $size > $maxContextLines) {
                $cappedEnd = $start + ($maxContextLines - $totalInRanges) - 1;
                if ($cappedEnd >= $start) {
                    $cappedRanges[] = [$start, $cappedEnd];
                }
                break;
            }

            $cappedRanges[] = [$start, $end];
            $totalInRanges += $size;
        }

        // Build output
        $output = '';
        $padWidth = max(4, (int) floor(log10($totalLines)) + 1);
        $prevEnd = 0;
        foreach ($cappedRanges as [$start, $end]) {
            if ($start > $prevEnd + 1 && '' !== $output) {
                $output .= "  ...\n";
            }

            for ($i = $start; $i <= $end; ++$i) {
                $lineNum = str_pad((string) $i, $padWidth, ' ', \STR_PAD_LEFT);
                $marker = \in_array($i, $failedLines, true) ? '→' : ' ';
                // fileLines is 0-indexed
                $lineContent = $fileLines[$i - 1] ?? '';
                $output .= \sprintf("%s %s: %s\n", $marker, $lineNum, $lineContent);
            }

            $prevEnd = $end;
        }

        return $output;
    }

    /**
     * Combine stdout and stderr into a single diagnostic string.
     */
    private function combinedPatchOutput(string $stdout, string $stderr): string
    {
        $parts = [];
        if ('' !== $stdout) {
            $parts[] = rtrim($stdout);
        }
        if ('' !== $stderr) {
            $parts[] = rtrim($stderr);
        }

        return implode("\n", $parts);
    }

    /**
     * Trim patch diagnostic output to a bounded size for LLM consumption.
     *
     * @param string $output   raw combined patch output
     * @param int    $maxLines maximum number of lines to include
     *
     * @return string trimmed output with truncation indicator if applicable
     */
    private function trimPatchOutput(string $output, int $maxLines = 20): string
    {
        $lines = explode("\n", $output);
        if (\count($lines) <= $maxLines) {
            return $output;
        }

        $trimmed = \array_slice($lines, 0, $maxLines);

        return implode("\n", $trimmed)."\n... (output truncated, ".(\count($lines) - $maxLines).' more lines)';
    }

    /* ── Temp file helpers ── */

    /**
     * Write patch content to a temp file.
     *
     * @return string Path to the temp patch file
     *
     * @throws ToolCallException when temp file creation or write fails
     */
    private function writePatchFile(string $patchContent): string
    {
        $patchFile = @tempnam(sys_get_temp_dir(), 'hatfield_patch_');

        if (false === $patchFile) {
            throw new ToolCallException('[E_PATCH_INFRA] Failed to create temp file for patch content.', retryable: true, hint: 'Check disk space and temp directory permissions.');
        }

        $written = @file_put_contents($patchFile, $patchContent);
        if (false === $written) {
            // Clean up on write failure
            if (is_file($patchFile)) {
                @unlink($patchFile);
            }
            throw new ToolCallException('[E_PATCH_INFRA] Failed to write patch content to temp file.', retryable: true, hint: 'Check disk space and temp directory permissions.');
        }

        return $patchFile;
    }

    /**
     * Count additions (+) and deletions (-) from the patch content,
     * excluding the --- and +++ header lines.
     *
     * @return array{additions: int, deletions: int}
     */
    private function computeStats(string $patchContent): array
    {
        $additions = 0;
        $deletions = 0;

        foreach (explode("\n", $patchContent) as $line) {
            $lineLen = \strlen($line);
            if (0 === $lineLen) {
                continue;
            }

            $firstChar = $line[0];

            if ('+' === $firstChar && !str_starts_with($line, '+++')) {
                ++$additions;
            } elseif ('-' === $firstChar && !str_starts_with($line, '---')) {
                ++$deletions;
            }
        }

        return ['additions' => $additions, 'deletions' => $deletions];
    }

    /**
     * Format the success message. If no changes were made, return a no-op message.
     */
    private function formatSuccess(string $targetPath, int $additions, int $deletions): string
    {
        if (0 === $additions && 0 === $deletions) {
            return 'No changes (patch produced identical content)';
        }

        return \sprintf(
            'Applied patch to %s (%d additions, %d deletions)',
            $targetPath,
            $additions,
            $deletions,
        );
    }

    /* ── File content helpers ── */

    /**
     * Check whether a regular file is non-empty and does not end with "\n".
     *
     * Unreadable files, directories, or empty files return false because
     * resolveAndVerifyTarget() already checked readability and the caller
     * handles other failure paths.
     */
    private function targetLacksTrailingNewline(string $targetPath): bool
    {
        if (!is_file($targetPath) || !is_readable($targetPath)) {
            return false; // Graceful degradation: caller already checked readability
        }

        $handle = @fopen($targetPath, 'rb');
        if (false === $handle) {
            return false; // Graceful degradation: file was readable, now is not
        }

        // Seek to the last byte
        if (-1 === fseek($handle, -1, \SEEK_END)) {
            fclose($handle);

            return false; // Empty file (or seek failed) — no trailing newline concern
        }

        $lastByte = fread($handle, 1);
        fclose($handle);

        return "\n" !== $lastByte;
    }
}
