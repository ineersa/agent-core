<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

/**
 * Classifies GNU patch failure output into error codes and builds
 * model-visible failure messages with sanitized GNU output and bounded
 * file-context windows.
 *
 * Also renders bounded post-apply changed chunks for success output.
 */
final class PatchFailureFormatter
{
    /**
     * Classify a patch failure from combined stdout+stderr output.
     *
     * @param string $combinedOutput               combined GNU patch stdout+stderr
     * @param bool   $normalizerDetectedTruncation set true when the patch
     *                                             normalizer detected a likely
     *                                             truncated hunk body
     *
     * @return array{code: string, retryable: bool, baseHint: string}
     */
    public function classifyFailure(string $combinedOutput, bool $normalizerDetectedTruncation = false): array
    {
        // Stale hunk / context mismatch
        if (preg_match('/Hunk\s+#\d+\s+FAILED/i', $combinedOutput)) {
            return [
                'code' => 'E_PATCH_STALE',
                'retryable' => true,
                'baseHint' => 'The patch context does not match the current file content. Re-read the file with `read` and regenerate the patch using plain `@@` hunk headers with the exact current context lines.',
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
            $baseHint = $normalizerDetectedTruncation
                ? 'The hunk body was incomplete (likely truncated). Retry with a plain `@@` hunk header and exact context copied from the latest `read` output. Do not calculate line numbers or counts — use plain `@@`.'
                : 'The patch appears malformed or is not a valid unified diff. Use plain `@@` hunk headers without line numbers or counts. Ensure the patch has ---/+++ headers, ends with a newline, and contains no markdown code fences or non-diff trailer lines (e.g. `--- End new file ---`). Do not copy line-number prefixes from `read` output into patch context lines; use only the raw file text.';

            return [
                'code' => 'E_PATCH_FORMAT',
                'retryable' => true,
                'baseHint' => $baseHint,
            ];
        }

        // Already applied / reversed / skipped by -N
        if (
            preg_match('/(?:reversed(?:\s+or\s+previously\s+applied)|already\s+applied|skipping\s+patch|patch\s+is\s+reversed|previously\s+applied)/i', $combinedOutput)
        ) {
            return [
                'code' => 'E_PATCH_NOOP',
                'retryable' => true,
                'baseHint' => 'The patch appears to be reversed or already applied. Re-read the current file with `read` to check whether the intended changes are already present.',
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
                'baseHint' => 'Some hunks failed to apply. The patch is all-or-nothing — no changes were made. Re-read the file with `read` and regenerate the patch using plain `@@` hunk headers with exact current context.',
            ];
        }

        // Generic failure — retryable, suggest re-reading
        return [
            'code' => 'E_PATCH_STALE',
            'retryable' => true,
            'baseHint' => 'The patch could not be applied. Re-read the file with `read` and regenerate the patch using plain `@@` hunk headers with exact current context.',
        ];
    }

    /**
     * Sanitize GNU patch combined output for model consumption.
     *
     * Removes lines that imply partial success — Hunk succeeded, offset/fuzz
     * info, file-checking lines — so the model only sees Actionable failure
     * diagnostics and never interprets ANY hunks as "applied" on a failed
     * edit.  The edit tool is all-or-nothing.
     */
    public function sanitizeFailureOutput(string $combinedOutput): string
    {
        $lines = explode("\n", $combinedOutput);
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Drop empty lines
            if ('' === $trimmed) {
                continue;
            }

            // Drop lines implying any hunk succeeded
            if (preg_match('/Hunk\s+#\d+\s+succeeded/i', $line)) {
                continue;
            }

            // Drop lines about offset/fuzz (partial-apply diagnostics)
            if (
                preg_match('/\boffset\s+\d+\s+lines?\b/i', $line)
                || preg_match('/\bwith\s+fuzz\s+\d+\b/i', $line)
            ) {
                continue;
            }

            // Drop GNU patch file-checking preamble line
            if (preg_match('/^checking\s+file\s+/i', $line)) {
                continue;
            }

            // Drop "/dev/null" references if they leak
            if (false !== stripos($line, '/dev/null')) {
                continue;
            }

            $filtered[] = $line;
        }

        return implode("\n", $filtered);
    }

    /**
     * Build a classified, bounded failure message suitable for LLM consumption.
     *
     * @return array{message: string, retryable: bool, hint: string}
     */
    public function buildFailureMessage(
        string $targetPath,
        string $stdout,
        string $stderr,
        string $originalContent,
        bool $noTrailingNewline,
        bool $detectedTruncation,
    ): array {
        $combined = $this->combinedPatchOutput($stdout, $stderr);
        $sanitized = $this->sanitizeFailureOutput($combined);
        $classification = $this->classifyFailure($combined, $detectedTruncation);
        $code = $classification['code'];
        $retryable = $classification['retryable'];
        $baseHint = $classification['baseHint'];

        $trimmed = $this->trimPatchOutput($sanitized);
        $phase = 'edit failed';

        // Build the message
        $message = \sprintf("[%s] %s for \"%s\":\n%s", $code, $phase, $targetPath, $trimmed);

        // For stale hunk failures: include bounded current-file context
        $currentContext = '';
        if ('E_PATCH_STALE' === $code) {
            $failedLines = $this->extractFailedHunkLines($combined);
            if ([] !== $failedLines) {
                $currentContext = $this->buildCurrentFileContext($originalContent, $failedLines);
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
     * Build a bounded current-file context window around failed line numbers.
     *
     * Returns a compact snippet with line numbers. The failed lines are
     * marked with a "→" prefix. Context extends ±$contextLines around each
     * failed line; overlapping windows are merged. Total output is capped.
     *
     * @param string $originalContent full original file content (bytes)
     * @param int[]  $failedLines     line numbers where hunks failed (1-based)
     * @param int    $contextLines    number of context lines above/below each failed line
     *
     * @return string formatted context window, or empty string on failure
     */
    public function buildCurrentFileContext(
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
            if (0 === \count($merged)) {
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
     * Build bounded post-apply changed chunks for success output.
     *
     * Parses the normalized patch to find which line ranges were changed
     * on the new side, then emits the patched file content ±contextLines
     * around each changed range.  Changed lines are marked with "→".
     * Overlapping windows are merged and total output is capped.
     *
     * @param string $originalContent        original file content before edit
     * @param string $patchedContent         file content after successful edit
     * @param string $normalizedPatchContent normalized patch with standard @@ headers
     * @param int    $contextLines           lines of context above/below each changed range
     * @param int    $maxContextLines        hard cap on total output lines
     *
     * @return string formatted changed contexts, or empty string
     */
    public function buildChangedContexts(
        string $originalContent,
        string $patchedContent,
        string $normalizedPatchContent,
        int $contextLines = 3,
        int $maxContextLines = 60,
    ): string {
        // Parse hunks to find which new-side lines are actual additions
        // (+ lines) vs context (space-prefixed).  Only addition lines
        // are marked with → in the output; context lines are unmarked.
        $changedSet = $this->parseNewSideAdditionLines($normalizedPatchContent);

        if ([] === $changedSet) {
            // If there are no additions (deletion-only hunks), still provide
            // bounded context around the deletion locations so the model can
            // verify the surrounding post-apply state.
            return $this->buildDeletionOnlyContexts($patchedContent, $normalizedPatchContent, $contextLines, $maxContextLines);
        }

        // Normalise patched content for line-level display.
        $normalized = str_replace(["\r\n", "\r"], "\n", $patchedContent);
        $fileLines = explode("\n", $normalized);
        if ([] !== $fileLines && '' === end($fileLines)) {
            array_pop($fileLines);
        }

        $totalLines = \count($fileLines);

        if (0 === $totalLines) {
            return '';
        }

        // Build inclusive context ranges around changed lines.
        $ranges = [];
        $changedLineNums = array_keys($changedSet);
        sort($changedLineNums);
        foreach ($changedLineNums as $line) {
            $start = max(1, $line - $contextLines);
            $end = min($totalLines, $line + $contextLines);
            $ranges[] = [$start, $end];
        }

        if (0 === \count($ranges)) {
            return '';
        }

        // Merge overlapping ranges.
        usort($ranges, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            if (0 === \count($merged)) {
                $merged[] = [$start, $end];

                continue;
            }
            $last = array_key_last($merged);
            if ($start <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        // Cap total lines.
        $capped = $this->capRanges($merged, $maxContextLines);
        $truncated = \count($capped) < \count($merged);
        $padWidth = max(4, (int) floor(log10($totalLines)) + 1);

        $output = '';
        $prevEnd = 0;
        foreach ($capped as [$start, $end]) {
            if ($start > $prevEnd + 1 && '' !== $output) {
                $output .= "  ...\n";
            }

            for ($i = $start; $i <= $end; ++$i) {
                $lineNum = str_pad((string) $i, $padWidth, ' ', \STR_PAD_LEFT);
                $marker = isset($changedSet[$i]) ? '→' : ' ';
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
     * Parse the normalized patch to find all new-side line numbers that
     * correspond to addition (+) lines in hunk bodies.
     *
     * Each addition line in a hunk body (prefixed with + but not +++) gets
     * mapped to its final line number in the patched file by tracking the
     * cumulative delta across hunks.
     *
     * @return array<int, true> line-number => true for each addition line
     */
    private function parseNewSideAdditionLines(string $normalizedPatchContent): array
    {
        $lines = explode("\n", $normalizedPatchContent);
        if ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        $changed = [];
        $inHunk = false;
        $newStart = 0;
        $ctxCount = 0;

        foreach ($lines as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,)?/m', $line, $m)) {
                $inHunk = true;
                $newStart = (int) $m[1];
                $ctxCount = 0;

                continue;
            }

            if ($inHunk) {
                $firstChar = $line[0] ?? '';
                if ('+' === $firstChar && !str_starts_with($line, '+++')) {
                    $changed[$newStart + $ctxCount] = true;
                    ++$ctxCount;
                } elseif (' ' === $firstChar || '' === $line) {
                    ++$ctxCount;
                } elseif ('-' === $firstChar) {
                    // Removals don't advance on the new side.
                } elseif (str_starts_with($line, '\\ ')) {
                    // \ No newline marker — don't advance.
                }
                // Any other character (blank line without space prefix, stray text,
                // or next @@ hunk) falls through — next iteration picks up the new hunk.
            }
        }

        return $changed;
    }

    /**
     * Build bounded post-apply context for deletion-only hunks (no additions).
     *
     * Provides the surrounding post-apply state so the model can verify
     * deletions without a follow-up read.  Lines are unmarked (no →) since
     * none were changed — instead a compact note describes the deletion.
     */
    private function buildDeletionOnlyContexts(
        string $patchedContent,
        string $normalizedPatchContent,
        int $contextLines,
        int $maxContextLines,
    ): string {
        // Parse old-side deletion ranges from the normalized patch.
        preg_match_all('/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@/m', $normalizedPatchContent, $matches, \PREG_SET_ORDER);

        $deletionRanges = [];
        foreach ($matches as $m) {
            $oldStart = (int) $m[1];
            $oldCount = '' === ($m[2] ?? '') ? 1 : (int) $m[2];
            if ($oldCount > 0) {
                $deletionRanges[] = ['oldStart' => $oldStart, 'oldEnd' => $oldStart + $oldCount - 1];
            }
        }

        if ([] === $deletionRanges) {
            return '';
        }

        // Compute approximate post-apply positions.
        // For pure deletions, the post-apply line near the deletion is the
        // same as the old-side start adjusted by cumulative delta.
        // This is an approximation; exact individual-line mapping not needed for verification.
        $normalized = str_replace(["\r\n", "\r"], "\n", $patchedContent);
        $fileLines = explode("\n", $normalized);
        if ([] !== $fileLines && '' === end($fileLines)) {
            array_pop($fileLines);
        }

        $totalLines = \count($fileLines);
        if (0 === $totalLines) {
            return '';
        }

        // Build context around each deletion location.
        $cumulativeDelta = 0;
        $ranges = [];
        $deletionNotes = [];
        foreach ($deletionRanges as $dr) {
            $newPos = $dr['oldStart'] + $cumulativeDelta;
            $newPos = max(1, min($totalLines, $newPos));
            $start = max(1, $newPos - $contextLines);
            $end = min($totalLines, $newPos + $contextLines);
            $ranges[] = [$start, $end];
            $deletionNotes[] = \sprintf('(deletion near line %d)', $newPos);

            $cumulativeDelta -= ($dr['oldEnd'] - $dr['oldStart'] + 1); // deletions reduce line count
        }

        if (0 === \count($ranges)) {
            return '';
        }

        // Merge overlapping ranges.
        usort($ranges, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as [$start, $end]) {
            if (0 === \count($merged)) {
                $merged[] = [$start, $end];

                continue;
            }
            $last = array_key_last($merged);
            if ($start <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        $capped = $this->capRanges($merged, $maxContextLines);
        $truncated = \count($capped) < \count($merged);
        $padWidth = max(4, (int) floor(log10($totalLines)) + 1);

        $output = '';
        $prevEnd = 0;
        foreach ($capped as $idx => [$start, $end]) {
            if ($start > $prevEnd + 1 && '' !== $output) {
                $output .= "  ...\n";
            }

            // Emit the deletion note for this range
            $note = $deletionNotes[$idx] ?? '';
            if ('' !== $note) {
                $output .= \sprintf("  %s\n", $note);
            }

            for ($i = $start; $i <= $end; ++$i) {
                $lineNum = str_pad((string) $i, $padWidth, ' ', \STR_PAD_LEFT);
                $lineContent = $fileLines[$i - 1] ?? '';
                $output .= \sprintf(" %s: %s\n", $lineNum, $lineContent);
            }

            $prevEnd = $end;
        }

        if ($truncated) {
            $output .= \sprintf("  ... (context truncated to %d lines)\n", $maxContextLines);
        }

        return $output;
    }

    /**
     * Cap merged ranges to a maximum total line count.
     *
     * @param list<array{int, int}> $ranges   merged sorted ranges
     * @param int                   $maxLines maximum total lines across all ranges
     *
     * @return list<array{int, int}>
     */
    private function capRanges(array $ranges, int $maxLines): array
    {
        $total = 0;
        $capped = [];
        foreach ($ranges as [$start, $end]) {
            $size = $end - $start + 1;
            if ($total + $size > $maxLines) {
                $cappedEnd = $start + ($maxLines - $total) - 1;
                if ($cappedEnd >= $start) {
                    $capped[] = [$start, $cappedEnd];
                }
                break;
            }
            $capped[] = [$start, $end];
            $total += $size;
        }

        return $capped;
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
}
