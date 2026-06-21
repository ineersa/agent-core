<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;

/**
 * Normalizes LLM-generated unified-diff patches before GNU patch processing.
 *
 * Repairs common LLM generation mistakes — markdown fences, hallucinated
 * non-diff trailers, missing terminal newlines, relaxed hunk headers
 * (plain @@ without counts), and hunk header counts that do not match the
 * body content — so that correct diffs with cosmetic issues apply without
 * the model needing to retry.
 *
 * The normalization is intentionally conservative: it only strips artifacts
 * that are never valid unified-diff syntax, and only repairs hunk counts
 * when the body lines are unambiguous.  It does NOT strip leading whitespace
 * from context lines or reflow content, because those could change intended
 * semantics.
 *
 * Truncated-patch safety:
 * When BOTH the actual old-line count and actual new-line count are strictly
 * less than the declared counts, the hunk body is stripped so GNU patch
 * rejects it as malformed.  This heuristic detects likely-truncated LLM output
 * (both sides under-shot) and fails-closed instead of silently applying a
 * partial edit.
 */
final class PatchNormalizer
{
    /**
     * Normalize an LLM-provided patch before GNU patch processing.
     *
     * @param string      $patchContent  raw LLM-generated patch
     * @param string|null $targetContent current target file content (required for relaxed hunk resolution)
     *
     * @return array{content: string, detectedTruncation: bool, truncationDetails: string}
     *
     * @throws ToolCallException when a relaxed hunk block is not found,
     *                           matches multiple locations, or hunks overlap
     */
    public function normalize(string $patchContent, ?string $targetContent = null): array
    {
        $detectedTruncation = false;
        $truncationDetails = '';

        // 1. Strip surrounding markdown code fences (only when they wrap the whole patch)
        $patchContent = $this->stripMarkdownFences($patchContent);

        // 2. Strip known non-diff hallucinated trailer artifacts
        $patchContent = $this->stripNonDiffTrailers($patchContent);

        // 3. Ensure the patch ends with a newline (avoids unexpected-EOF from GNU patch)
        if ('' !== $patchContent && !str_ends_with($patchContent, "\n")) {
            $patchContent .= "\n";
        }

        // 4. Resolve relaxed hunk headers (plain @@ without line numbers/counts)
        //    before count repair, so repair sees standard headers.
        if (null !== $targetContent) {
            $patchContent = $this->resolveRelaxedHunks($patchContent, $targetContent);
        }

        // 5. Repair hunk header counts to match actual body content.
        $patchContent = $this->repairHunkCounts($patchContent, $detectedTruncation, $truncationDetails);

        return [
            'content' => $patchContent,
            'detectedTruncation' => $detectedTruncation,
            'truncationDetails' => $truncationDetails,
        ];
    }

    /**
     * Strip surrounding markdown code fences when the entire patch is wrapped.
     *
     * Accepts any language tag (```diff, ```patch, ```text, bare ```, etc.)
     * because a fence that wraps the entire content is never diff content.
     * Only strips when the fences surround the entire content (i.e. begin at
     * start, end at end after trimming).  Does not touch fences that appear
     * inside the patch body.
     *
     * Also strips a trailing pure-fence line and everything after it when the
     * meaningful diff has already ended (session-8 regression: valid patch
     * followed by ``` and prose/pseudo tool-call text).
     */
    private function stripMarkdownFences(string $patchContent): string
    {
        $trimmed = trim($patchContent);

        // Match: optional language tag (any word chars), then content, then closing fence.
        // Bounded to [^\n]* so we never eat into file-header lines that start with ---.
        if (preg_match('/^```[^\n]*\n(.*)\n```\s*$/s', $trimmed, $matches)) {
            return $matches[1];
        }

        // Strip trailing pure-fence line and everything after it when the
        // fence appears AFTER the diff content is complete.  This handles
        // the case where a valid diff body is followed by a stray ```
        // line and prose / pseudo tool-call text (session-8 pattern).
        //
        // Safety: a pure unprefixed ``` line is never valid diff content —
        // only lines prefixed with space, -, +, or \ are valid hunk body
        // lines.  A bare ``` after a complete diff hunk is a generated
        // wrapper/trailer artifact and safe to strip.
        $fencePos = strpos($patchContent, "\n```");
        if (false !== $fencePos && $this->trailingFenceIsAfterCompleteDiff($patchContent, $fencePos)) {
            $patchContent = substr($patchContent, 0, $fencePos);
        }

        // After stripping trailing fence+prose, re-check for whole-patch fences
        // that may have been exposed by the strip.
        $trimmed2 = trim($patchContent);
        if (preg_match('/^```[^\n]*\n(.*)\n```\s*$/s', $trimmed2, $matches)) {
            return $matches[1];
        }

        return $patchContent;
    }

    /**
     * Heuristic: does a trailing ``` fence appear AFTER a complete diff
     * (at least one properly-closed hunk has been parsed before the fence)?
     *
     * Returns true when the portion before the fence contains a valid @@
     * hunk header followed by diff body lines (space, -, +, or \ prefix),
     * indicating the diff portion is already complete and the fence is a
     * generated wrapper/trailer artifact.
     */
    private function trailingFenceIsAfterCompleteDiff(string $patchContent, int $fencePos): bool
    {
        $beforeFence = substr($patchContent, 0, $fencePos);

        // Must contain at least one @@ hunk header (the diff portion exists)
        if (!preg_match('/^@@\s/m', $beforeFence)) {
            return false;
        }

        // The fence must appear on its own line (or with whitespace only)
        $remainder = substr($patchContent, $fencePos);
        if (!preg_match('/^\n```\s*(\n|$)/', $remainder)) {
            return false;
        }

        // The text after the fence must NOT contain another diff header.
        // If it does, the fence is between hunks — don't strip.
        $afterFence = substr($remainder, 1); // Skip the \n before ```
        $afterFenceBody = preg_replace('/^```\s*\n/', '', $afterFence);
        if (preg_match('/^@@\s/m', $afterFenceBody)) {
            return false;
        }

        return true;
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
        // "--- End file ---" artifacts. Case-insensitive. Repeated stripping
        // removes multiple stacked artifacts (e.g. model appends both
        // "--- End new file ---" then "--- End file ---").
        $pattern = '/\n--- End (?:new |of )?file ---\s*$/i';
        $prev = $patchContent;

        while (true) {
            $next = preg_replace($pattern, '', $prev);
            if ($next === $prev) {
                return $next;
            }
            $prev = $next;
        }
    }

    /* ── Relaxed hunk header resolution ── */

    /**
     * Resolve relaxed (plain @@ without line numbers/counts) hunk headers
     * by locating the old-side block in the current target file content.
     *
     * Standard headers matching `@@ -a,b +c,d @@` pass through unchanged.
     * Relaxed headers (just `@@` or `@@ optional section text`) are resolved
     * into standard headers by:
     * 1. Parsing the hunk body to extract old-side and new-side blocks.
     * 2. Locating the old-side block as an exact contiguous match in the
     *    current file content.
     * 3. Computing oldStart, oldCount, newCount, and newStart (with
     *    cumulative delta from prior hunks).
     *
     * All hunks are validated for ascending file order and non-overlap
     * before headers are rewritten.
     *
     * @param string $patchContent  cleaned patch content (fences/trailers removed,
     *                              newline ensured) with potentially-relaxed hunks
     * @param string $targetContent current target file content
     *
     * @return string patch with all relaxed hunks resolved to standard
     *                `@@ -a,b +c,d @@` headers
     *
     * @throws ToolCallException when a relaxed hunk old block is not found
     *                           (stale context), matches multiple locations
     *                           (ambiguous), or hunks overlap / are out of order
     */
    private function resolveRelaxedHunks(string $patchContent, string $targetContent): string
    {
        $standardHeaderPattern = '/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(.*)$/';

        // Normalise target file content for line-level matching.
        $targetNormalized = str_replace(["\r\n", "\r"], "\n", $targetContent);
        $fileLines = explode("\n", $targetNormalized);
        if ([] !== $fileLines && '' === end($fileLines)) {
            array_pop($fileLines);
        }

        // Split patch into lines, dropping trailing explode artifact.
        $lines = explode("\n", $patchContent);
        if ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        $totalLines = \count($lines);

        // Initialised at each hunk parse; kept accessible for the
        // close-hunk block at the end of the loop.
        $declaredOldCountForHunk = 0;
        $declaredNewCountForHunk = 0;

        // ── First pass: identify all hunks and parse their bodies ──
        $hunks = [];
        $inHunk = false;
        $isRelaxed = false;
        $hunkHeaderIdx = -1;
        $declaredOldStart = 0;
        $declaredOldCount = 0;
        $declaredNewStart = 0;
        $declaredNewCount = 0;
        $hunkSuffix = '';
        /** @var list<string> */
        $oldBlockLines = [];
        /** @var list<string> */
        $newBlockLines = [];
        $actualOldCount = 0;
        $actualNewCount = 0;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '@@')) {
                // Close previous hunk
                if ($inHunk) {
                    $hunks[] = $isRelaxed
                        ? [
                            'type' => 'relaxed',
                            'headerIdx' => $hunkHeaderIdx,
                            'bodyEndIdx' => $i,
                            'oldBlock' => $oldBlockLines,
                            'newBlock' => $newBlockLines,
                        ]
                        : [
                            'type' => 'standard',
                            'headerIdx' => $hunkHeaderIdx,
                            'bodyEndIdx' => $i,
                            'oldStart' => $declaredOldStart,
                            'oldCount' => $declaredOldCountForHunk,
                            'newStart' => $declaredNewStart,
                            'newCount' => $declaredNewCountForHunk,
                            'actualOldCount' => $actualOldCount,
                            'actualNewCount' => $actualNewCount,
                        ];
                }

                // Detect relaxed vs standard header
                $isRelaxed = !preg_match($standardHeaderPattern, $line);
                $hunkHeaderIdx = $i;
                $inHunk = true;
                $oldBlockLines = [];
                $newBlockLines = [];
                $actualOldCount = 0;
                $actualNewCount = 0;

                if (!$isRelaxed && preg_match($standardHeaderPattern, $line, $m)) {
                    $declaredOldStart = (int) $m[1];
                    $declaredOldCount = '' === $m[2] ? 1 : (int) $m[2];
                    $declaredNewStart = (int) $m[3];
                    $declaredNewCount = '' === $m[4] ? 1 : (int) $m[4];
                    $hunkSuffix = $m[5];
                    // Store declared counts for the standard-hunk record.
                    // Actual body counts are accumulated below separately.
                    $declaredOldCountForHunk = $declaredOldCount;
                    $declaredNewCountForHunk = $declaredNewCount;
                } else {
                    $declaredOldStart = 0;
                    $declaredOldCount = 0;
                    $declaredNewStart = 0;
                    $declaredNewCount = 0;
                    $declaredOldCountForHunk = 0;
                    $declaredNewCountForHunk = 0;
                }

                continue;
            }

            if ($inHunk) {
                $firstChar = $line[0] ?? '';

                if ('' === $line || ' ' === $firstChar) {
                    // Context or blank line — part of both old and new blocks.
                    $oldBlockLines[] = $line;
                    $newBlockLines[] = $line;
                    ++$actualOldCount;
                    ++$actualNewCount;
                } elseif ('-' === $firstChar) {
                    $oldBlockLines[] = $line;
                    ++$actualOldCount;
                } elseif ('+' === $firstChar) {
                    $newBlockLines[] = $line;
                    ++$actualNewCount;
                } elseif (str_starts_with($line, '\\ ')) {
                    // "\ No newline at end of file" marker — not a body line.
                }
            }
        }

        // Close last hunk
        if ($inHunk) {
            $hunks[] = $isRelaxed
                ? [
                    'type' => 'relaxed',
                    'headerIdx' => $hunkHeaderIdx,
                    'bodyEndIdx' => $totalLines,
                    'oldBlock' => $oldBlockLines,
                    'newBlock' => $newBlockLines,
                ]
                : [
                    'type' => 'standard',
                    'headerIdx' => $hunkHeaderIdx,
                    'bodyEndIdx' => $totalLines,
                    'oldStart' => $declaredOldStart,
                    'oldCount' => $declaredOldCountForHunk,
                    'newStart' => $declaredNewStart,
                    'newCount' => $declaredNewCountForHunk,
                    'actualOldCount' => $actualOldCount,
                    'actualNewCount' => $actualNewCount,
                ];
        }

        if ([] === $hunks) {
            return $patchContent;
        }

        // ── Second pass: resolve relaxed hunks by locating old blocks ──
        $cumulativeDelta = 0;
        $prevNewStart = 0;

        foreach ($hunks as $idx => &$hunk) {
            $lastHeaderIdx = $hunk['headerIdx'];
            $hunk['suffix'] = '';

            if ('standard' === $hunk['type']) {
                // For standard hunks, preserve the DECLARED counts as resolved counts
                // (repairHunkCounts will correct mismatches later).  Use actual body
                // counts for overlap/delta validation only — the overlap check must
                // respect what the body actually covers, not what the header claims.
                if (preg_match($standardHeaderPattern, $lines[$lastHeaderIdx], $m)) {
                    $hunk['suffix'] = $m[5];
                }

                $declaredOld = $hunk['oldCount'];
                $declaredNew = $hunk['newCount'];
                $actualOld = $hunk['actualOldCount'] ?? $declaredOld;
                $actualNew = $hunk['actualNewCount'] ?? $declaredNew;

                $hunk['resolvedOldStart'] = $hunk['oldStart'] ?? 0;
                $hunk['resolvedNewStart'] = $hunk['newStart'] ?? 0;
                $hunk['resolvedOldCount'] = $declaredOld;
                $hunk['resolvedNewCount'] = $declaredNew;
                // Use actual counts for overlap validation.
                $hunk['overlapOldCount'] = $actualOld;
                $hunk['overlapNewCount'] = $actualNew;
            } else {
                // Relaxed hunk: locate old block in file
                $oldBlock = $hunk['oldBlock'];
                $newBlock = $hunk['newBlock'];

                if ([] === $oldBlock) {
                    throw new ToolCallException('[E_PATCH_FORMAT] edit failed: a plain @@ hunk has no context or removal lines — cannot locate it in the file. Include context lines (unchanged lines) around the edit.', retryable: true, hint: 'Include 3–4 unchanged context lines around each edit so the tool can locate the hunk in the file.');
                }

                $match = $this->findExactBlockMatch($fileLines, $oldBlock);

                if ([] === $match) {
                    // No match: stale context
                    $preview = implode("\n", \array_slice($oldBlock, 0, 4));
                    throw new ToolCallException("[E_PATCH_STALE] edit failed: no changes were applied — a plain @@ hunk old block was not found in the current file. Use a targeted `read` with `offset` and `limit` around the affected region, then regenerate using exact current context lines.\n\nOld block (first 4 lines):\n{$preview}", retryable: true, hint: 'The plain @@ hunk stale-context block was not found in the current file. If the old block shows your desired content, you wrote a changed line as context — existing-line changes need `-current line` + `+desired line`; context lines must match the file exactly. Use a targeted `read` with `offset` and `limit` around the affected region, then retry using the exact current context from the latest read output.');
                }

                if (\count($match) > 1) {
                    throw new ToolCallException('[E_PATCH_FORMAT] edit failed: no changes were applied — a plain @@ hunk old block matched multiple locations in the file ('.\count($match).' matches at lines '.implode(', ', $match).'). Add more context lines so the old block matches only one location.', retryable: true, hint: 'The old-block context matched more than one location. Add more unchanged context lines (from above or below the edit) to make the match unique.');
                }

                $hunk['resolvedOldStart'] = (int) $match[0];
                $hunk['resolvedOldCount'] = \count($oldBlock);
                $hunk['resolvedNewCount'] = \count($newBlock);
                $hunk['newBlock'] = $newBlock;
            }
        }
        unset($hunk);

        // ── Third pass: validate order and overlap (using actual body counts) ──
        for ($i = 0; $i < \count($hunks); ++$i) {
            $hunk = &$hunks[$i];
            $oldStart = $hunk['resolvedOldStart'];
            $overlapOldCount = $hunk['overlapOldCount'] ?? $hunk['resolvedOldCount'];
            $oldEnd = $oldStart + $overlapOldCount - 1;

            // Ascending order
            if (0 !== $i) {
                $prevOverlapOldCount = $hunks[$i - 1]['overlapOldCount'] ?? $hunks[$i - 1]['resolvedOldCount'];
                $prevEnd = $hunks[$i - 1]['resolvedOldStart'] + $prevOverlapOldCount - 1;
                if ($oldStart <= $prevEnd) {
                    throw new ToolCallException('[E_PATCH_FORMAT] edit failed: hunk '.($i + 1).' old-block range ['.$oldStart.'-'.$oldEnd.'] overlaps with previous hunk old-block range ending at '.$prevEnd.'. Provide hunks in ascending file order with non-overlapping old-side blocks.', retryable: true, hint: 'Hunks must be in ascending file order and their old-side blocks must not overlap. Reorder the hunks so they progress from top to bottom of the file.');
                }
            }
        }
        unset($hunk);

        // ── Fourth pass: compute cumulative newStart and rewrite headers ──
        foreach ($hunks as &$hunk) {
            $newStart = $hunk['resolvedOldStart'] + $cumulativeDelta;
            $newCount = $hunk['resolvedNewCount'];
            $oldCount = $hunk['resolvedOldCount'];

            $lines[$hunk['headerIdx']] = \sprintf(
                '@@ -%d,%d +%d,%d @@%s',
                $hunk['resolvedOldStart'],
                $oldCount,
                $newStart,
                $newCount,
                $hunk['suffix'],
            );

            // Use actual body counts for delta computation so repairHunkCounts
            // can detect mismatch vs declared counts preserved in headers.
            $deltaOld = $hunk['overlapOldCount'] ?? $oldCount;
            $deltaNew = $hunk['overlapNewCount'] ?? $newCount;
            $cumulativeDelta += $deltaNew - $deltaOld;
        }
        unset($hunk);

        return implode("\n", $lines)."\n";
    }

    /**
     * Find all exact contiguous occurrences of a block of lines in the file.
     *
     * Each line in the block is compared without its prefix character
     * (the leading space, -, or +), so the match operates on the file
     * content independently of the diff marker.
     *
     * @param list<string> $fileLines  full current file content (line-normalised)
     * @param list<string> $blockLines old-side block lines with diff prefixes
     *
     * @return int[] 1-based starting line numbers where the block matches
     */
    private function findExactBlockMatch(array $fileLines, array $blockLines): array
    {
        // Strip the first character (diff prefix) from each block line
        // to get the actual file content for matching.
        $searchLines = [];
        foreach ($blockLines as $line) {
            $searchLines[] = '' === $line ? '' : substr($line, 1);
        }

        $fileCount = \count($fileLines);
        $blockLen = \count($searchLines);
        $matches = [];

        // Slide through file lines looking for an exact contiguous match.
        for ($i = 0; $i <= $fileCount - $blockLen; ++$i) {
            $match = true;

            for ($j = 0; $j < $blockLen; ++$j) {
                if (($fileLines[$i + $j] ?? null) !== $searchLines[$j]) {
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

    /* ── Hunk count repair ── */

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
     *
     * @param string $patchContent       normalized patch content (relaxed hunks resolved)
     * @param bool   $detectedTruncation output flag set true when truncation is detected
     * @param string $truncationDetails  output: declared-vs-actual count info for failure messaging
     *
     * @return string patch with repaired hunk counts
     */
    private function repairHunkCounts(string $patchContent, bool &$detectedTruncation, string &$truncationDetails = ''): string
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
                        $detectedTruncation,
                        $truncationDetails,
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
                $detectedTruncation,
                $truncationDetails,
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
     * @param string[] $lines              All patch lines (mutated in place)
     * @param int      $hdrIdx             Index of the hunk header line
     * @param int      $bodyEndIdx         Index of first line AFTER the hunk body
     *                                     (next hunk header, or end of array)
     * @param int      $oldStart           Old-file start line
     * @param int      $newStart           New-file start line
     * @param string   $suffix             Trailing section name / text after @@
     * @param int      $actualOld          Actual old-line count counted from body
     * @param int      $actualNew          Actual new-line count counted from body
     * @param int      $declaredOld        Old-line count declared in header
     * @param int      $declaredNew        New-line count declared in header
     * @param bool     $detectedTruncation output flag set true when truncation is detected
     * @param string   $truncationDetails  output: declared-vs-actual count info for failure messaging
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
        bool &$detectedTruncation,
        string &$truncationDetails = '',
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
            // Both sides under-declared — possible truncation.  Build the
            // declared-vs-actual detail string for failure messaging first,
            // then fail closed (never attempt auto-repair, because the
            // trailing-context heuristic is too weak to reliably distinguish
            // over-declaration from genuine truncation — a truncated generation
            // that happens to land on a context line would be silently
            // partially applied if repaired).
            if ('' === $truncationDetails) {
                $truncationDetails = \sprintf(
                    'Numbered hunk header declared %d old / %d new lines, but the hunk body contains %d old / %d new lines.',
                    $declaredOld, $declaredNew, $actualOld, $actualNew,
                );
            }

            // OR-accumulate (do not clear earlier truncation flags from
            // previous hunks — last-write-wins would drop their details).
            $detectedTruncation = true;

            // Strip all body lines for this hunk (keep the header line).
            // GNU patch reads the header, finds blank lines with no diff
            // prefix, and reports a format error.
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
}
