<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

/**
 * Builds bounded file-context snippets for edit tool success and stale failures.
 */
final class PatchFailureFormatter
{
    /**
     * @param int[] $failedLines 1-based line numbers in the current file
     */
    public function buildCurrentFileContext(
        string $originalContent,
        array $failedLines,
        int $contextLines = 4,
    ): string {
        $normalized = str_replace(["\r\n", "\r"], "\n", $originalContent);
        $fileLines = explode("\n", $normalized);

        if ([] !== $fileLines && '' === end($fileLines)) {
            array_pop($fileLines);
        }

        $totalLines = \count($fileLines);

        if (0 === $totalLines || [] === $failedLines) {
            return '';
        }

        $ranges = [];
        foreach ($failedLines as $line) {
            if ($line > $totalLines) {
                continue;
            }
            $start = max(1, $line - $contextLines);
            $end = min($totalLines, $line + $contextLines);
            $ranges[] = [$start, $end];
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

    /**
     * @param list<array{0: int, 1: int}> $ranges
     *
     * @return list<array{0: int, 1: int}>
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
}
