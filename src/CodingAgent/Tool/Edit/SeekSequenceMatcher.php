<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

/**
 * Codex-style multi-pass line sequence matcher.
 */
final class SeekSequenceMatcher
{
    /**
     * @param list<string> $lines
     * @param list<string> $pattern
     */
    public function findUniqueMatch(array $lines, array $pattern, int $startIndex = 0, bool $eof = false): ?int
    {
        if ([] === $pattern) {
            return $startIndex;
        }

        if (\count($pattern) > \count($lines)) {
            return null;
        }

        $match = $this->seekSequence($lines, $pattern, $startIndex, $eof);
        if (null === $match) {
            return null;
        }

        $second = $this->seekSequence($lines, $pattern, $match + 1, false);
        if (null !== $second) {
            return null;
        }

        return $match;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $pattern
     */
    public function seekSequence(array $lines, array $pattern, int $startIndex = 0, bool $eof = false): ?int
    {
        if ([] === $pattern) {
            return $startIndex;
        }

        if (\count($pattern) > \count($lines)) {
            return null;
        }

        $searchStart = $eof ? max(0, \count($lines) - \count($pattern)) : $startIndex;
        $searchEnd = \count($lines) - \count($pattern);

        for ($pass = 0; $pass < 5; ++$pass) {
            for ($i = $searchStart; $i <= $searchEnd; ++$i) {
                if ($this->linesMatchAt($lines, $pattern, $i, $pass)) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $pattern
     */
    private function linesMatchAt(array $lines, array $pattern, int $start, int $pass): bool
    {
        $patternLen = \count($pattern);
        for ($j = 0; $j < $patternLen; ++$j) {
            $line = $lines[$start + $j] ?? '';
            $pat = $pattern[$j];
            if (!$this->lineEquals($line, $pat, $pass)) {
                return false;
            }
        }

        return true;
    }

    private function lineEquals(string $line, string $pattern, int $pass): bool
    {
        return match ($pass) {
            0 => $line === $pattern,
            1 => rtrim($line, " \t\r\n\0\x0B") === rtrim($pattern, " \t\r\n\0\x0B"),
            2 => trim($line) === trim($pattern),
            3 => $this->normalizeUnicode(trim($line)) === $this->normalizeUnicode(trim($pattern)),
            default => false,
        };
    }

    private function normalizeUnicode(string $text): string
    {
        $map = [
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
            "\u{00A0}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ', "\u{2009}" => ' ',
            "\u{2026}" => '...',
        ];

        return strtr($text, $map);
    }
}
