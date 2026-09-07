<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Cli;

/**
 * Normalizes jbcontext search JSON into ranked tool fields.
 *
 * Verified CLI shape:
 * results[] -> { result: { scoredText.similarity, sourcePosition.relativePath,
 * startOffset, endOffset, indexItemType }, content, contentStartLine }
 */
final class JbcontextSearchResultNormalizer
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{
     *     path: string,
     *     start_line: ?int,
     *     similarity: ?float,
     *     content: string
     * }>
     */
    public static function normalize(array $payload): array
    {
        $rows = $payload['results'] ?? null;
        if (!\is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $inner = $row['result'] ?? null;
            if (!\is_array($inner)) {
                continue;
            }

            $source = $inner['sourcePosition'] ?? null;
            $path = '';
            if (\is_array($source)) {
                $path = trim((string) ($source['relativePath'] ?? ''));
            }
            if ('' === $path) {
                continue;
            }

            $similarity = null;
            $scored = $inner['scoredText'] ?? null;
            if (\is_array($scored) && isset($scored['similarity']) && is_numeric($scored['similarity'])) {
                $similarity = (float) $scored['similarity'];
            }

            $startLine = null;
            if (isset($row['contentStartLine']) && is_numeric($row['contentStartLine'])) {
                $startLine = (int) $row['contentStartLine'];
            }

            $content = (string) ($row['content'] ?? '');
            if (self::isPhpHeaderOnlyChunk($content)) {
                continue;
            }

            $out[] = [
                'path' => $path,
                'start_line' => $startLine,
                'similarity' => $similarity,
                'content' => $content,
            ];
        }

        return $out;
    }

    /**
     * Drops index chunks that are only a PHP open tag and/or declare(strict_types=1).
     * Keeps declare+namespace/code and non-PHP text.
     */
    private static function isPhpHeaderOnlyChunk(string $content): bool
    {
        $split = preg_split('/\R/u', $content);
        $lines = false === $split ? [] : $split;
        $nonEmpty = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ('' !== $trimmed) {
                $nonEmpty[] = $trimmed;
            }
        }
        if ([] === $nonEmpty) {
            return false;
        }

        foreach ($nonEmpty as $line) {
            if (1 === preg_match('/^<\?(?:php)?(?:\s+declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;)?$/i', $line)) {
                continue;
            }
            if (1 === preg_match('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;$/i', $line)) {
                continue;
            }

            return false;
        }

        return true;
    }
}
