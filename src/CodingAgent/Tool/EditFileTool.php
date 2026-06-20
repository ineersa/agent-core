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
        private readonly \Psr\Log\LoggerInterface $logger,
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
     * @throws \RuntimeException on cancellation or timeout — these originate from
     *                           {@see ToolRuntime::run()} checkpoints, not from the patch
     *                           lifecycle itself
     */
    public function __invoke(array $arguments): string
    {
        $this->validateArguments($arguments);

        // Wrap core logic in cancellation checkpoints
        return $this->toolRuntime->run(function () use ($arguments): string {
            $targetPath = $this->resolveAndVerifyTarget($arguments['path']);
            $patchContent = $this->normalizePatch($arguments['patch']);

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
                '`@@` hunk headers must reference the original line numbers shown by `read` via `cat -n` (e.g. `@@ -42,6 +42,8 @@`). The tool automatically repairs common hunk header count / body content mismatches, but correct counts are still best.',
                'Keep hunks tight: include only enough unchanged context (typically 3–4 lines) for the patch to apply reliably.',
                'The patch may contain multiple hunks to edit different parts of the file.',
                'Do NOT wrap the patch in markdown code fences (```diff, ```patch, ```).',
                'Do NOT include non-diff trailer lines such as `--- End new file ---` or `--- End file ---`.',
                'Do NOT copy the line-number prefix or whitespace/tab separator from `read` / `cat -n` output into patch lines. Unified-diff context lines MUST start with a leading space (the ` ` prefix); that space is required — keep it. Only strip the numbered prefix and the whitespace between the line number and file text (e.g. from `    42␣file content` keep ` file content` with the leading space).',
                'Ensure the patch ends with a trailing newline. The tool adds one if missing, but including it yourself avoids unexpected-EOF failures.',
                'The target file must already exist — use the write tool to create new files.',
                'Whitespace mismatches between the patch and the target file are handled automatically (tolerant matching).',
                'On success, the tool returns only the file path and addition/deletion stats. Do NOT echo or expect the full applied diff back.',
                'If the patch produces no changes, the tool reports "No changes" without modifying the file.',
                'If an edit fails with a stale-hunk error (context mismatch), the error includes a current-file context window with exact line numbers. Re-read the file with `read` and retry with a regenerated patch using the exact current context from `cat -n`.',
                'If an edit fails with a format error, check that the patch has proper ---/+++ headers and @@ hunk headers, ends with a newline, and contains no markdown fences or non-diff trailers.',
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

    /* ── Patch normalization (LLM-generated diff repair) ── */

    /**
     * Normalize an LLM-provided patch before GNU patch processing.
     *
     * This step repairs common LLM generation mistakes — markdown fences,
     * hallucinated non-diff trailers, missing terminal newlines, and hunk
     * header counts that do not match the body content — so that correct
     * diffs with cosmetic issues apply without the model needing to retry.
     *
     * The normalization is intentionally conservative: it only strips
     * artifacts that are never valid unified-diff syntax, and only repairs
     * hunk counts when the body lines are unambiguous.  It does NOT strip
     * leading whitespace from context lines or reflow content, because
     * those could change intended semantics.
     */
    private function normalizePatch(string $patchContent): string
    {
        // 1. Strip surrounding markdown code fences (only when they wrap the whole patch)
        $patchContent = $this->stripMarkdownFences($patchContent);

        // 2. Strip known non-diff hallucinated trailer artifacts
        $patchContent = $this->stripNonDiffTrailers($patchContent);

        // 3. Ensure the patch ends with a newline (avoids unexpected-EOF from GNU patch)
        if ('' !== $patchContent && !str_ends_with($patchContent, "\n")) {
            $patchContent .= "\n";
        }

        // 4. Repair hunk header counts to match actual body content
        $patchContent = $this->repairHunkCounts($patchContent);

        return $patchContent;
    }

    /**
     * Strip surrounding markdown code fences when the entire patch is wrapped.
     *
     * Accepts any language tag (```diff, ```patch, ```text, bare ```, etc.)
     * because a fence that wraps the entire content is never diff content.
     * Only strips when the fences surround the entire content (i.e. begin at
     * start, end at end after trimming).  Does not touch fences that appear
     * inside the patch body.
     */
    private function stripMarkdownFences(string $patchContent): string
    {
        $trimmed = trim($patchContent);

        // Match: optional language tag (any word chars), then content, then closing fence.
        // Bounded to [^\n]* so we never eat into file-header lines that start with ---.
        if (preg_match('/^```[^\n]*\n(.*)\n```\s*$/s', $trimmed, $matches)) {
            return $matches[1];
        }

        return $patchContent;
    }

    /**
     * Strip LLM-hallucinated non-diff trailer lines from the patch.
     *
     * Targeted artifacts:
     * - "--- End new file ---"  (and "--- End file ---", "--- End of file ---")
     *   — common LLM artifact that follows a `\ No newline at end of file`
     *   marker and is NOT valid unified-diff syntax.
     *
     * Only strips at the end of the patch text so legitimate content lines
     * that happen to match are not falsely removed.
     */
    private function stripNonDiffTrailers(string $patchContent): string
    {
        // Strip trailing "--- End new file ---", "--- End of file ---", or
        // "--- End file ---" artifacts. Case-insensitive.
        return preg_replace('/\n--- End (?:new |of )?file ---\s*$/i', '', $patchContent);
    }

    /**
     * Repair unified-diff hunk header counts to match actual body content.
     *
     * For each hunk, counts context (` `), removal (`-`), and addition (`+`)
     * lines in the body and rewrites the `@@ -oldStart,oldCount +newStart,newCount @@`
     * header with the actual counts.  `\ No newline at end of file` marker
     * lines are ignored.  Empty/blank lines within hunks are treated as
     * context (a common LLM artifact where the leading space on blank lines
     * was dropped).
     *
     * When the original header omits a count (e.g. `@@ -1 +3 @@`), a count
     * of 1 is assumed per unified-diff convention.
     *
     * ── Truncated-patch safety ──
     *
     * If BOTH the actual old-line count is strictly less than the declared
     * old-line count AND the actual new-line count is strictly less than the
     * declared new-line count, the hunk header is NOT repaired AND the hunk
     * body is stripped so GNU patch rejects it as malformed/unexpected EOF.
     * This is a conservative heuristic: having both sides under-shot suggests
     * the patch content was truncated mid-body during LLM generation, not
     * just miscounted.  Stripping the body ensures fail-closed: GNU patch
     * reports a format error (`E_PATCH_FORMAT`), the file is untouched, and
     * the model can retry with a complete patch.
     *
     * When at least one side's actual count is >= declared count (e.g. LLM
     * over-estimated counts or only one side was truncated), we still repair
     * because the mismatch is almost certainly a counting error, not a
     * content-truncation problem.
     */
    private function repairHunkCounts(string $patchContent): string
    {
        // Matches hunk headers like:
        //   @@ -42,6 +42,8 @@
        //   @@ -1,20 +1,40 @@ function name
        //   @@ -1 +3 @@
        // Captures declared counts (groups 2 and 4 are optional — omitted count defaults to 1).
        $hunkPattern = '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(.*)$/m';

        // explode produces a trailing empty string for newline-terminated
        // input.  Drop it so it is not counted as a blank context line.
        $lines = explode("\n", $patchContent);
        if ([] !== $lines && '' === $lines[\count($lines) - 1]) {
            array_pop($lines);
        }

        $totalLines = \count($lines);
        $inHunk = false;
        $oldCount = 0;
        $newCount = 0;
        $declaredOldCount = 0;
        $declaredNewCount = 0;
        $hunkHeaderIdx = -1;
        $hunkStartOld = 0;
        $hunkStartNew = 0;
        $hunkSuffix = '';

        foreach ($lines as $i => $line) {
            if (preg_match($hunkPattern, $line, $m)) {
                // Flush previous hunk (body ended at index $i — current header)
                if ($inHunk) {
                    $this->repairSingleHunk(
                        $lines, $hunkHeaderIdx, $i,
                        $hunkStartOld, $hunkStartNew, $hunkSuffix,
                        $oldCount, $newCount,
                        $declaredOldCount, $declaredNewCount,
                    );
                }

                $inHunk = true;
                $hunkHeaderIdx = $i;
                $hunkStartOld = (int) $m[1];
                $hunkStartNew = (int) $m[3];
                $hunkSuffix = $m[5];
                // Omitted count in header defaults to 1 per unified-diff convention.
                $declaredOldCount = '' === $m[2] ? 1 : (int) $m[2];
                $declaredNewCount = '' === $m[4] ? 1 : (int) $m[4];
                $oldCount = 0;
                $newCount = 0;

                continue;
            }

            if ($inHunk) {
                if ('' === $line) {
                    // Blank line — treat as context (common LLM artifact
                    // where leading space on blank context line was dropped).
                    ++$oldCount;
                    ++$newCount;
                } elseif (str_starts_with($line, '\\ ')) {
                    // \ No newline at end of file — marker, do not count.
                    // Matched more specifically (starts with "\ " rather
                    // than just "\") to avoid misclassifying content lines
                    // that happen to start with a backslash.
                } elseif (' ' === ($line[0] ?? '')) {
                    // Context line — counts for both old and new.
                    ++$oldCount;
                    ++$newCount;
                } elseif ('-' === ($line[0] ?? '')) {
                    // Removal line — counts for old.
                    ++$oldCount;
                } elseif ('+' === ($line[0] ?? '')) {
                    // Addition line — counts for new.
                    ++$newCount;
                }
                // Any other prefix (e.g. stray text) is ignored for counting.
            }
        }

        // Flush the last hunk (body ended at end-of-array)
        if ($inHunk) {
            $this->repairSingleHunk(
                $lines, $hunkHeaderIdx, $totalLines,
                $hunkStartOld, $hunkStartNew, $hunkSuffix,
                $oldCount, $newCount,
                $declaredOldCount, $declaredNewCount,
            );
        }

        // Re-append trailing newline that was stripped from the explode
        // artifact so the patch ends with exactly one newline.
        return implode("\n", $lines)."\n";
    }

    /**
     * Rewrite a single hunk header with repaired counts, or strip its body
     * when the truncation heuristic fires.
     *
     * @param string[] $lines       All patch lines (mutated in place)
     * @param int      $hdrIdx      Index of the hunk header line
     * @param int      $bodyEndIdx  Index of first line AFTER the hunk body
     *                              (next hunk header, or end of array)
     * @param int      $oldStart    Old-file start line
     * @param int      $newStart    New-file start line
     * @param string   $suffix      Trailing section name / text after @@
     * @param int      $actualOld   Actual old-line count counted from body
     * @param int      $actualNew   Actual new-line count counted from body
     * @param int      $declaredOld Old-line count declared in header
     * @param int      $declaredNew New-line count declared in header
     */
    private function repairSingleHunk(
        array &$lines,
        int $hdrIdx,
        int $bodyEndIdx,
        int $oldStart,
        int $newStart,
        string $suffix,
        int $actualOld,
        int $actualNew,
        int $declaredOld,
        int $declaredNew,
    ): void {
        // Perfectly-counted hunk — including zero-count hunks (e.g. pure
        // insertion @@ -1,0 +1,3 @@ or pure deletion @@ -1,3 +1,0 @@).
        // No repair needed; leave header and body untouched.
        if ($actualOld === $declaredOld && $actualNew === $declaredNew) {
            return;
        }

        // Truncation safety: when BOTH sides under-shoot vs declared counts,
        // the patch content is likely truncated, not miscounted.  We cannot
        // simply leave the mismatch for GNU patch because GNU patch -F3
        // applies partial hunks with fuzz tolerance (verified behaviour:
        // declared @@ -2,4 +2,4 @@ with 3 body lines → "Hunk #1 succeeded
        // at 2 with fuzz 2").  That would silently apply an incomplete edit.
        //
        // Instead, strip the hunk body to empty lines.  GNU patch treats
        // blank body lines (lines with no space/-/+/\ prefix) as a format
        // error ("malformed patch at line N") regardless of file content —
        // blank context matching cannot silently no-op.
        //
        // Reference case: declared @@ -1,5 +1,5 @@ but body has only 2–3
        // lines.  GNU patch -F3 would apply this with fuzz; stripping the
        // body forces a format error → E_PATCH_FORMAT.  File is untouched.
        //
        // When at least one side actual >= declared (LLM over-count /
        // miscount is far more common than truncation), we repair safely.
        if ($actualOld < $declaredOld && $actualNew < $declaredNew) {
            // Strip all body lines for this hunk (keep the header line).
            // GNU patch reads the header, finds blank lines with no diff
            // prefix, and reports a format error → E_PATCH_FORMAT.
            for ($j = $hdrIdx + 1; $j < $bodyEndIdx; ++$j) {
                $lines[$j] = '';
            }

            return;
        }

        // Safe repair: at least one side actual >= declared.  Rewrite
        // the hunk header with actual counts, preserving zero-count
        // hunks (e.g. pure insertion with declared 0,0 stays 0,0).
        $lines[$hdrIdx] = \sprintf(
            '@@ -%d,%d +%d,%d @@%s',
            $oldStart, $actualOld,
            $newStart, $actualNew,
            $suffix,
        );
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
            } catch (\Throwable $e) {
                // Lock release failure is non-fatal during cleanup;
                // logged for diagnostics per project caught-exception rule.
                $this->logger->warning('Lock release failed during edit tool cleanup', [
                    'component' => 'edit_tool',
                    'event_type' => 'edit_tool.lock_release_failed',
                    'exception' => $e::class,
                    'exception_message' => $e->getMessage(),
                ]);
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

        if (false === $written) {
            // No bytes were written — target is untouched, rollback unnecessary.
            throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": write returned false (permission/disk error).', $targetPath), retryable: true, hint: 'Check file permissions and disk space, then retry.');
        }

        // Short write: best-effort rollback to restore original bytes in-place.
        $restored = @file_put_contents($targetPath, $originalContent, \LOCK_EX);
        $rollbackOk = false !== $restored && $restored === \strlen($originalContent);
        $rollbackStatus = $rollbackOk
            ? 'Original content restored.'
            : 'Rollback may be incomplete (write failure or short write) — verify file integrity with `read` or `git diff`.';

        throw new ToolCallException(\sprintf('[E_PATCH_WRITE] Failed to write patched content to "%s": short write (%d of %d bytes). %s', $targetPath, $written, \strlen($patchedContent), $rollbackStatus), retryable: true, hint: 'Check file permissions and disk space, then retry.');
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
     *                                  or cancelled/timedOut flags to decide
     *                                  success, failure, cancellation, or timeout
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
     *                                  or cancelled/timedOut flags to decide
     *                                  success, failure, cancellation, or timeout
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

        // Malformed / not a unified diff — includes unexpected-EOF,
        // garbage input, missing headers, and hunk count mismatches
        // reported as malformed by GNU patch.
        // Checked AFTER stale-hunk so that output containing both "Hunk
        // FAILED" and "unexpected end" still classifies as stale (the
        // stale context is the primary actionable signal).
        if (
            preg_match('/(?:only\s+garbage\s+was\s+found|not\s+a\s+unified\s+diff|malformed|missing\s+header|unrecognized\s+input|can\'t\s+find\s+file\s+to\s+patch|unexpected\s+end\s+of\s+(?:file|patch)|patch\s+unexpectedly\s+ends)/i', $combinedOutput)
        ) {
            return [
                'code' => 'E_PATCH_FORMAT',
                'retryable' => true,
                'baseHint' => 'The patch appears malformed or is not a valid unified diff. Provide a standard `diff -u` format patch with proper ---/+++ headers and @@ hunk headers. Ensure each hunk header count matches the actual body content, the patch ends with a newline, and no markdown code fences or non-diff trailer lines (e.g. `--- End new file ---`) are included. Do not copy line-number prefixes or tab/space indentation from `read` / `cat -n` output into patch context lines; use only the raw file text after the line-number separator.',
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
        // Normalise CRLF / lone-CR line endings to plain LF before
        // splitting, so lone-CR content does not leak raw carriage
        // returns into model-visible context.
        $normalized = str_replace(["\r\n", "\r"], "\n", $originalContent);
        $fileLines = explode("\n", $normalized);

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

        // Build inclusive ranges to display.
        // Skip impossible ranges where the failed line number exceeds
        // the total line count (patch targets lines beyond file end).
        $ranges = [];
        foreach ($failedLines as $line) {
            if ($line > $totalLines) {
                continue;
            }
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

        // Build output.
        // Compute pad width once from total line count for consistent
        // column alignment across all ranges.
        $padWidth = max(4, (int) floor(log10($totalLines)) + 1);
        $truncated = \count($cappedRanges) < \count($merged);
        $output = '';
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

        if ($truncated) {
            $output .= \sprintf("  ... (context truncated to %d lines)\n", $maxContextLines);
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

        $addWord = 1 === $additions ? 'addition' : 'additions';
        $delWord = 1 === $deletions ? 'deletion' : 'deletions';

        return \sprintf(
            'Applied patch to %s (%d %s, %d %s)',
            $targetPath,
            $additions, $addWord,
            $deletions, $delWord,
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
