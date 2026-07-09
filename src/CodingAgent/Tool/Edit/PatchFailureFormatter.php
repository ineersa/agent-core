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
     * @param string $truncationDetails            declared-vs-actual hunk count
     *                                             details from the normalizer
     *                                             (empty when not applicable)
     *
     * @return array{code: string, retryable: bool, baseHint: string}
     */
    public function classifyFailure(string $combinedOutput, bool $normalizerDetectedTruncation = false, string $truncationDetails = ''): array
    {
        // Stale hunk / context mismatch
        if (preg_match('/Hunk\s+#\d+\s+FAILED/i', $combinedOutput)) {
            return [
                'code' => 'E_PATCH_STALE',
                'retryable' => true,
                'baseHint' => 'The patch context does not match the current file content. Use the provided current-file context window below as the exact content, or use a targeted `read` with `offset` and `limit` around the affected region. Then regenerate using plain `@@` hunk headers with the exact current context.',
            ];
        }

        // Malformed / not a plain-@@ patch — includes unexpected-EOF,
        // garbage input, missing headers, and hunk count mismatches
        // reported as malformed by GNU patch.
        // Checked AFTER stale-hunk so that output containing both "Hunk
        // FAILED" and "unexpected end" still classifies as stale (the
        // stale context is the primary actionable signal).
        if (
            preg_match('/(?:only\s+garbage\s+was\s+found|not\s+a\s+unified\s+diff|malformed|missing\s+header|unrecognized\s+input|can\'t\s+find\s+file\s+to\s+patch|unexpected\s+end\s+of\s+(?:file|patch)|patch\s+unexpectedly\s+ends)/i', $combinedOutput)
        ) {
            if ($normalizerDetectedTruncation) {
                $countsPart = '' !== $truncationDetails ? ' '.$truncationDetails : '';
                $baseHint = 'The hunk body was incomplete (likely truncated).'.$countsPart.' Retry with exactly `@@` as the hunk header — do not use numbered headers. Copy only the exact context from the latest `read` output.';
            } else {
                $baseHint = 'The patch appears malformed or is not a valid plain-@@ patch. Use plain `@@` hunk headers without line numbers or counts. Ensure the patch has ---/+++ headers, ends with a newline, and contains no markdown code fences or non-diff trailer lines (e.g. `--- End new file ---`). Do not copy line-number prefixes from `read` output into patch context lines; use only the raw file text.';
            }

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
                'baseHint' => 'Some hunks failed to apply. The patch is all-or-nothing — no changes were made. Use the provided current-file context window below, or use a targeted `read` with `offset` and `limit` around the affected region. Then regenerate using plain `@@` hunk headers with exact current context.',
            ];
        }

        // Generic failure — retryable, suggest re-reading
        return [
            'code' => 'E_PATCH_STALE',
            'retryable' => true,
            'baseHint' => 'The patch could not be applied. Use a targeted `read` with `offset` and `limit` around the affected region, or use the provided current-file context window if present. Then regenerate using plain `@@` hunk headers with exact current context.',
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
        string $truncationDetails = '',
    ): array {
        $combined = $this->combinedPatchOutput($stdout, $stderr);
        $sanitized = $this->sanitizeFailureOutput($combined);
        $classification = $this->classifyFailure($combined, $detectedTruncation, $truncationDetails);
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
     * @param int[] $changedLineNumbers 1-based line numbers in patched content
     */
    public function buildChangedContextsFromLineNumbers(
        string $patchedContent,
        array $changedLineNumbers,
        int $contextLines = 3,
        int $maxContextLines = 60,
    ): string {
        if ([] === $changedLineNumbers) {
            return '';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $patchedContent);
        $fileLines = explode("\n", $normalized);
        if ([] !== $fileLines && '' === end($fileLines)) {
            array_pop($fileLines);
        }

        $totalLines = \count($fileLines);
        if (0 === $totalLines) {
            return '';
        }

        $changedSet = [];
        foreach ($changedLineNumbers as $line) {
            if ($line >= 1 && $line <= $totalLines) {
                $changedSet[$line] = true;
            }
        }

        if ([] === $changedSet) {
            return '';
        }

        $ranges = [];
        foreach (array_keys($changedSet) as $line) {
            $ranges[] = [max(1, $line - $contextLines), min($totalLines, $line + $contextLines)];
        }

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
        $changedSet = $this->parseNewSideAdditionLines($normalizedPatchContent, $originalContent);

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
     * When $originalContent is provided, matches each hunk's old-side
     * block against it to compute actual applied positions — correct even
     * when GNU patch applies with fuzz/offset that shifts the final
     * positions away from the declared @@ header numbers.  Without
     * originalContent, falls back to declared header positions.
     *
     * Each addition line in a hunk body (prefixed with + but not +++) gets
     * mapped to its final line number in the patched file by tracking the
     * cumulative delta across hunks.
     *
     * @return array<int, true> line-number => true for each addition line
     */
    private function parseNewSideAdditionLines(string $normalizedPatchContent, string $originalContent = ''): array
    {
        $lines = explode("\n", $normalizedPatchContent);
        if ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        // When original content is available, use actual block matching
        // to compute line numbers — accounts for GNU patch offset/fuzz.
        if ('' !== $originalContent) {
            return $this->parseNewSideAdditionLinesWithMatching($lines, $originalContent);
        }

        // Fallback: use declared header positions.
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
     * Compute actual new-side line numbers by matching each hunk's
     * old-side block against the original file content.
     *
     * This accounts for GNU patch fuzz/offset: declared @@ -start
     * may differ from the actual match position in the original file.
     * By re-deriving actual positions from the original content, arrow
     * markers in success output always land on the right lines.
     *
     * @param string[] $patchLines      normalized patch lines (already CRLF-normalized, no trailing empty element)
     * @param string   $originalContent raw original file content before edit
     *
     * @return array<int, true>
     */
    private function parseNewSideAdditionLinesWithMatching(array $patchLines, string $originalContent): array
    {
        $origNormalized = str_replace(["\r\n", "\r"], "\n", $originalContent);
        $origFileLines = explode("\n", $origNormalized);
        if ([] !== $origFileLines && '' === end($origFileLines)) {
            array_pop($origFileLines);
        }

        // Parse hunks: collect header info and body lines.
        $hunks = [];
        $currentHunk = null;
        foreach ($patchLines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/m', $line, $m)) {
                if (null !== $currentHunk) {
                    $hunks[] = $currentHunk;
                }
                $currentHunk = [
                    'oldStart' => (int) $m[1],
                    'newStart' => (int) $m[3],
                    'bodyLines' => [],
                    'additions' => 0,
                    'deletions' => 0,
                ];

                continue;
            }

            if (null !== $currentHunk) {
                $firstChar = $line[0] ?? '';
                $currentHunk['bodyLines'][] = $line;
                if ('+' === $firstChar && !str_starts_with($line, '+++')) {
                    ++$currentHunk['additions'];
                } elseif ('-' === $firstChar && !str_starts_with($line, '---')) {
                    ++$currentHunk['deletions'];
                }
            }
        }
        if (null !== $currentHunk) {
            $hunks[] = $currentHunk;
        }

        $changed = [];
        $cumulativeDelta = 0;

        foreach ($hunks as $hunk) {
            // Build old-side block: context (space) + deletion (-) lines,
            // stripping the first character (diff marker) to get the
            // actual file content for matching.
            $oldBlock = [];
            $addLocalPositions = []; // local positions within this hunk's new-side (0-based)
            $ctxCount = 0;

            foreach ($hunk['bodyLines'] as $bodyLine) {
                $firstChar = $bodyLine[0] ?? '';
                if (' ' === $firstChar || '' === $bodyLine) {
                    $oldBlock[] = '' === $bodyLine ? '' : substr($bodyLine, 1);
                    ++$ctxCount;
                } elseif ('-' === $firstChar) {
                    $oldBlock[] = substr($bodyLine, 1);
                } elseif ('+' === $firstChar && !str_starts_with($bodyLine, '+++')) {
                    $addLocalPositions[] = $ctxCount;
                    ++$ctxCount;
                } elseif (str_starts_with($bodyLine, '\\ ')) {
                    // \ No newline marker — don't advance.
                }
            }

            if (0 === \count($addLocalPositions)) {
                // Deletion-only hunk — advance cumulative delta and skip.
                $cumulativeDelta += $hunk['additions'] - $hunk['deletions'];

                continue;
            }

            // Find where the old-side block actually matched in the original file.
            $matches = $this->findExactBlockMatch($origFileLines, $oldBlock);

            if ([] === $matches) {
                // No match found — fall back to declared header line numbers
                // for this hunk.  This should be vanishingly rare since the
                // patch was already applied successfully (GNU patch found a
                // match).  Possible in edge cases like patching a file that
                // was concurrently modified outside the lock.
                foreach ($addLocalPositions as $localPos) {
                    $changed[$hunk['newStart'] + $localPos] = true;
                }
            } else {
                // Pick the match nearest the declared oldStart so arrows
                // land on the actual patched lines when duplicate blocks
                // exist in the file.  GNU patch applies near the declared
                // line number, not the first match.
                $actualOldStart = $this->nearestMatch($matches, $hunk['oldStart']);
                $actualNewStart = $actualOldStart + $cumulativeDelta;

                foreach ($addLocalPositions as $localPos) {
                    $changed[$actualNewStart + $localPos] = true;
                }
            }

            $cumulativeDelta += $hunk['additions'] - $hunk['deletions'];
        }

        return $changed;
    }

    /**
     * Find exact contiguous matches of $blockLines in $fileLines.
     *
     * Each entry in $blockLines is raw file content (no diff prefix).
     * Empty strings match empty lines.  Returns 1-based start positions
     * of all matches.
     *
     * @param string[] $fileLines  normalised file content lines (no trailing empty element, no CR)
     * @param string[] $blockLines raw block content lines
     *
     * @return int[] 1-based start positions
     */
    private function findExactBlockMatch(array $fileLines, array $blockLines): array
    {
        $fileCount = \count($fileLines);
        $blockLen = \count($blockLines);

        if (0 === $blockLen || $blockLen > $fileCount) {
            return [];
        }

        $matches = [];

        for ($i = 0; $i <= $fileCount - $blockLen; ++$i) {
            $match = true;

            for ($j = 0; $j < $blockLen; ++$j) {
                if (($fileLines[$i + $j] ?? null) !== $blockLines[$j]) {
                    $match = false;

                    break;
                }
            }

            if ($match) {
                $matches[] = $i + 1; // 1-based
            }
        }

        return $matches;
    }

    /**
     * From a list of 1-based match positions, pick the one nearest a
     * target line number (the declared oldStart).
     *
     * @param int[] $matches 1-based match positions (must be non-empty)
     * @param int   $target  declared oldStart line number
     *
     * @return int 1-based position of the nearest match
     */
    private function nearestMatch(array $matches, int $target): int
    {
        $best = $matches[0];
        $bestDist = abs($target - $best);

        for ($i = 1, $len = \count($matches); $i < $len; ++$i) {
            $dist = abs($target - $matches[$i]);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $matches[$i];
            }
        }

        return $best;
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
