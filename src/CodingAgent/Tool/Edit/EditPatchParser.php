<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;

/**
 * Parses single-file Codex-style hunk bodies (no ---/+++ envelope).
 */
final class EditPatchParser
{
    private const string EOF_MARKER = '*** End of File';

    /**
     * @return list<EditPatchChunkDTO>
     */
    public function parse(string $rawPatch): array
    {
        $patch = $this->stripOuterMarkdownFence(trim($rawPatch));
        if ('' === $patch) {
            throw $this->formatError('Patch is empty.');
        }

        $this->rejectLegacySyntax($patch);

        if (!str_ends_with($patch, "\n")) {
            $patch .= "\n";
        }

        $lines = explode("\n", $patch);
        if ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        $chunks = [];
        $i = 0;
        $lineCount = \count($lines);

        while ($i < $lineCount) {
            if ($this->isHunkHeaderLine($lines[$i])) {
                [$chunk, $consumed] = $this->parseChunk($lines, $i);
                $chunks[] = $chunk;
                $i += $consumed;
                continue;
            }

            if ('' === trim($lines[$i])) {
                ++$i;
                continue;
            }

            throw $this->formatError(\sprintf('Unexpected line outside a hunk: "%s".', $this->preview($lines[$i])));
        }

        if ([] === $chunks) {
            throw $this->formatError('Patch contains no @@ hunks.');
        }

        return $chunks;
    }

    private function rejectLegacySyntax(string $patch): void
    {
        if (preg_match('/^\*\*\* Begin Patch/m', $patch)) {
            throw $this->formatError('Codex patch envelope is not supported. Use hunk bodies with @@ only.');
        }
        if (preg_match('/^\*\*\* (Add|Delete|Update|Move) File:/m', $patch)) {
            throw $this->formatError('Multi-file patch markers are not supported.');
        }

        $preamble = $this->preambleBeforeFirstHunk($patch);
        if ('' !== $preamble) {
            if (preg_match('/^---\s/m', $preamble) || preg_match('/^\+\+\+\s/m', $preamble)) {
                throw $this->formatError('---/+++ file headers are not supported. Use @@ hunks only.');
            }
            if (preg_match('/^@@ -\d+(?:,\d+)? \+\d+/m', $preamble)) {
                throw $this->formatError('Numbered unified-diff @@ headers are not supported. Use plain @@ or @@ <seek hint>.');
            }
        }
    }

    private function preambleBeforeFirstHunk(string $patch): string
    {
        if (!preg_match('/^@@/m', $patch, $m, \PREG_OFFSET_CAPTURE)) {
            return $patch;
        }

        return substr($patch, 0, $m[0][1]);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: EditPatchChunkDTO, 1: int}
     */
    private function parseChunk(array $lines, int $start): array
    {
        $seekHints = [];
        $i = $start;
        $lineCount = \count($lines);

        while ($i < $lineCount && $this->isHunkHeaderLine($lines[$i])) {
            $seekHints[] = $this->parseSeekHint($lines[$i]);
            ++$i;
        }

        $oldLines = [];
        $newLines = [];
        $endOfFile = false;

        while ($i < $lineCount) {
            $line = $lines[$i];

            if ($this->isHunkHeaderLine($line)) {
                break;
            }

            if (self::EOF_MARKER === rtrim($line)) {
                $endOfFile = true;
                ++$i;
                break;
            }

            if ('' === $line) {
                $oldLines[] = '';
                $newLines[] = '';
                ++$i;
                continue;
            }

            $prefix = $line[0];
            $payload = substr($line, 1);

            if (' ' === $prefix) {
                $oldLines[] = $payload;
                $newLines[] = $payload;
                ++$i;
                continue;
            }

            if ('-' === $prefix) {
                $oldLines[] = $payload;
                ++$i;
                continue;
            }

            if ('+' === $prefix) {
                $newLines[] = $payload;
                ++$i;
                continue;
            }

            throw $this->formatError(\sprintf('Invalid hunk body line: "%s". Hunk body lines must begin with a diff prefix: a leading space for unchanged context, `-` for removals, or `+` for additions. If this line is unchanged content, prefix it with one space.', $this->preview($line)));
        }

        if ([] === $oldLines && [] === $newLines && !$endOfFile) {
            throw $this->formatError('Hunk has no context or change lines.');
        }

        return [new EditPatchChunkDTO($seekHints, $oldLines, $newLines, $endOfFile), $i - $start];
    }

    private function isHunkHeaderLine(string $line): bool
    {
        return str_starts_with($line, '@@');
    }

    private function parseSeekHint(string $line): string
    {
        if ('@@' === rtrim($line)) {
            return '';
        }

        if (preg_match('/^@@\s+(.*)$/', $line, $m)) {
            $hint = $m[1];
            if (preg_match('/^-\d+(?:,\d+)? \+\d+(?:,\d+)?(?: @@)?$/', $hint)) {
                throw $this->formatError('Numbered unified-diff @@ headers are not supported. Use plain @@ or @@ <seek hint>.');
            }

            return $hint;
        }

        throw $this->formatError(\sprintf('Malformed @@ header: "%s".', $this->preview($line)));
    }

    private function stripOuterMarkdownFence(string $patch): string
    {
        if (preg_match('/^```[^\n]*\n(.*)\n```\s*$/s', $patch, $m)) {
            return trim($m[1]);
        }

        return $patch;
    }

    private function preview(string $line): string
    {
        if (\strlen($line) > 80) {
            return substr($line, 0, 77).'...';
        }

        return $line;
    }

    private function formatError(string $detail): ToolCallException
    {
        return new ToolCallException(
            \sprintf('[E_PATCH_FORMAT] %s', $detail),
            retryable: true,
            hint: 'Use @@ hunks; each body line must begin with a diff prefix (leading space for unchanged context, `-` for removals, `+` for additions). Blank unchanged lines inside hunks are still context lines; represent them as a line containing one leading space. Optional @@ <seek hint>, stacked @@ hints, and *** End of File for EOF anchoring. No ---/+++, numbered headers, or *** Begin Patch envelope.',
        );
    }
}
