<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;

/**
 * Computes line replacements from parsed hunks using seek_sequence matching.
 */
final class EditPatchApplicator
{
    public function __construct(
        private readonly SeekSequenceMatcher $matcher = new SeekSequenceMatcher(),
    ) {
    }

    /**
     * @param list<EditPatchChunkDTO> $chunks
     *
     * @return list<EditReplacementDTO>
     */
    public function computeReplacements(array $chunks, string $originalContent): array
    {
        [$lines, $hadTrailingNewline] = $this->splitFileLines($originalContent);
        $replacements = [];
        $lineIndex = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $lastHintIndex = null;
            foreach ($chunk->seekHints as $hint) {
                if ('' === $hint) {
                    continue;
                }

                $hintIndex = $this->matcher->findUniqueMatch($lines, [$hint], $lineIndex, false);
                if (null === $hintIndex) {
                    if (null !== $this->matcher->seekSequence($lines, [$hint], $lineIndex, false)) {
                        throw $this->ambiguous($chunkIndex, $hint);
                    }

                    throw $this->stale($chunkIndex, $hint, $lines, $lineIndex);
                }

                $lastHintIndex = $hintIndex;
                $lineIndex = $hintIndex + 1;
            }

            if (null !== $lastHintIndex) {
                $lineIndex = $lastHintIndex;
            }

            if ([] === $chunk->oldLines) {
                $insertAt = $chunk->endOfFile ? \count($lines) : $lineIndex;
                $replacements[] = new EditReplacementDTO($insertAt, 0, $chunk->newLines);
                if (!$chunk->endOfFile) {
                    $lineIndex = $insertAt + \count($chunk->newLines);
                }
                continue;
            }

            $matchIndex = $this->findOldBlock($lines, $chunk->oldLines, $lineIndex, $chunk->endOfFile, $chunkIndex);
            if (null === $matchIndex) {
                throw $this->stale($chunkIndex, implode("\n", \array_slice($chunk->oldLines, 0, 3)), $lines, $lineIndex);
            }

            $replacements[] = new EditReplacementDTO($matchIndex, \count($chunk->oldLines), $chunk->newLines);
            $lineIndex = $matchIndex + \count($chunk->oldLines);
        }

        return $replacements;
    }

    /**
     * @param list<string>             $lines
     * @param list<EditReplacementDTO> $replacements
     */
    public function applyReplacements(array $lines, array $replacements, bool $hadTrailingNewline): string
    {
        usort($replacements, static fn (EditReplacementDTO $a, EditReplacementDTO $b): int => $b->startIndex <=> $a->startIndex);

        foreach ($replacements as $replacement) {
            array_splice($lines, $replacement->startIndex, $replacement->oldLength, $replacement->newLines);
        }

        if ([] === $lines) {
            return $hadTrailingNewline ? "\n" : '';
        }

        $joined = implode("\n", $lines);

        return $hadTrailingNewline ? $joined."\n" : $joined;
    }

    /**
     * @return array{0: list<string>, 1: bool}
     */
    public function splitFileLines(string $content): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $hadTrailingNewline = str_ends_with($normalized, "\n");
        $lines = explode("\n", $normalized);
        if ($hadTrailingNewline && [] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        return [$lines, $hadTrailingNewline];
    }

    /**
     * @param list<string> $lines
     * @param list<string> $oldLines
     */
    private function findOldBlock(array $lines, array $oldLines, int $startIndex, bool $eof, int $chunkIndex): ?int
    {
        foreach ([$oldLines, $this->trimTrailingEmptyPattern($oldLines)] as $pattern) {
            if ([] === $pattern) {
                continue;
            }

            $unique = $this->matcher->findUniqueMatch($lines, $pattern, $startIndex, $eof);
            if (null !== $unique) {
                return $unique;
            }

            if (null !== $this->matcher->seekSequence($lines, $pattern, $startIndex, $eof)) {
                throw $this->ambiguous($chunkIndex, $pattern[0] ?? '');
            }
        }

        return null;
    }

    /**
     * @param list<string> $pattern
     *
     * @return list<string>
     */
    private function trimTrailingEmptyPattern(array $pattern): array
    {
        if ([] === $pattern || '' !== $pattern[array_key_last($pattern)]) {
            return [];
        }

        $trimmed = $pattern;
        array_pop($trimmed);

        return $trimmed;
    }

    /**
     * @param list<string> $lines
     */
    private function stale(int $chunkIndex, string $needle, array $lines, int $lineIndex): ToolCallException
    {
        $contextLine = min(max(1, $lineIndex + 1), max(1, \count($lines)));

        return new ToolCallException(
            \sprintf('[E_PATCH_STALE] Hunk #%d context not found in file.', $chunkIndex + 1),
            retryable: true,
            hint: \sprintf('Could not locate: "%s". Use read with offset/limit around line %d and regenerate the patch with exact current context.', $this->preview($needle), $contextLine),
        );
    }

    private function ambiguous(int $chunkIndex, string $needle): ToolCallException
    {
        return new ToolCallException(
            \sprintf('[E_PATCH_AMBIGUOUS] Hunk #%d seek hint matches multiple locations.', $chunkIndex + 1),
            retryable: true,
            hint: \sprintf('Add more surrounding context or stacked @@ hints so "%s" is unique.', $this->preview($needle)),
        );
    }

    private function preview(string $text): string
    {
        $oneLine = str_replace("\n", '\\n', $text);
        if (\strlen($oneLine) > 80) {
            return substr($oneLine, 0, 77).'...';
        }

        return $oneLine;
    }
}
