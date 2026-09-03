<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Exception\RenderException;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * App-owned ScreenWriter with Pi-style native viewport bookkeeping.
 *
 * Installed at runtime via {@see PiStyleScreenWriterAliasInstaller} before the
 * first `new Symfony\Component\Tui\Tui` so Tui's private
 * `new ScreenWriter($terminal)` constructs this class under the aliased FQCN
 * `Symfony\Component\Tui\Render\ScreenWriter`.
 *
 * Public behaviour matches the locked Symfony TUI ScreenWriter (ANSI handling,
 * line-width guard, cursor markers/shapes, scrollOffset windowing, shrink/delete,
 * resize full-clear, CSI 2026 sync frames).
 *
 * Domain state:
 * - logical frame rows (`previousLines` / write input)
 * - physical viewport top and height (`previousViewportTop` / `previousHeight`)
 * - logical hardware cursor row (`hardwareCursorRow`)
 *
 * Algorithm delta adapted from `@mariozechner/pi-tui` (`packages/tui/src/tui.ts`
 * doRender), MIT License Copyright (c) 2025 Mario Zechner:
 * - convert logical buffer rows to physical screen rows for cursor movement
 * - when the update target is below the previous viewport bottom, move to the
 *   physical bottom, emit exact CRLF count so the terminal scrolls naturally,
 *   and advance viewport/hardware logical-row bookkeeping
 * - position the hardware cursor with relative CUU/CUD + CHA
 *
 * @internal
 */
final class PiStyleScreenWriter
{
    private const string PRINTABLE_ASCII = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    /** @var string[] */
    private array $previousLines = [];

    private int $previousWidth = 0;

    private int $previousHeight = 0;

    private int $previousViewportTop = 0;

    private int $cursorRow = 0;

    private int $hardwareCursorRow = 0;

    private int $maxLinesRendered = 0;

    private bool $showHardwareCursor = true;

    private int $scrollOffset = 0;

    /** @var string[] */
    private array $previousRawLines = [];

    /** @var array{row: int, col: int, shape: int}|null */
    private ?array $previousCursorPos = null;

    public function __construct(
        private readonly TerminalInterface $terminal,
    ) {
    }

    public function setShowHardwareCursor(bool $enabled): void
    {
        if ($this->showHardwareCursor === $enabled) {
            return;
        }

        $this->showHardwareCursor = $enabled;

        if (!$enabled) {
            $this->terminal->hideCursor();
        }
    }

    /**
     * Set the scroll offset (lines from bottom).
     *
     * When the content exceeds the viewport, the viewport normally shows
     * the bottom portion. A positive scroll offset shifts the viewport
     * up by that many lines (Symfony ScreenWriter semantics; slices the
     * logical frame before differential write).
     */
    public function setScrollOffset(int $offset): void
    {
        if ($this->scrollOffset !== $offset = max(0, $offset)) {
            $this->scrollOffset = $offset;
            $this->reset();
        }
    }

    public function getScrollOffset(): int
    {
        return $this->scrollOffset;
    }

    /**
     * Write ANSI lines to the terminal using differential rendering.
     *
     * @param string[] $lines The new content to display
     */
    public function writeLines(array $lines): void
    {
        // Apply scroll offset: when content exceeds the viewport, slice to
        // show a window shifted up from the bottom by scrollOffset lines.
        // At scrollOffset=0 the full logical buffer is preserved (native scroll).
        if ($this->scrollOffset > 0) {
            $totalLines = \count($lines);
            $rows = $this->terminal->getRows();
            if ($totalLines > $rows) {
                $maxOffset = $totalLines - $rows;
                $effectiveOffset = min($this->scrollOffset, $maxOffset);
                $startLine = $totalLines - $rows - $effectiveOffset;
                $lines = \array_slice($lines, $startLine, $rows);
            }
        }

        if ([] !== $this->previousLines && $this->previousWidth === $this->terminal->getColumns() && $lines === $this->previousRawLines) {
            $this->positionHardwareCursor($this->previousCursorPos, \count($this->previousLines));

            return;
        }

        $rawLines = $lines;
        ['lines' => $lines, 'cursor_pos' => $cursorPos, 'first_changed' => $firstChanged, 'last_changed' => $lastChanged] = $this->prepareLines($lines);

        $this->writeInternal($lines, $cursorPos, $firstChanged, $lastChanged);
        $this->previousRawLines = $rawLines;
        $this->previousCursorPos = $cursorPos;
    }

    /**
     * Clear rendering state, forcing a full re-render on next write.
     *
     * The scroll offset is preserved so that a forced re-render does not
     * jump back to the bottom of the content.
     */
    public function reset(): void
    {
        $this->previousLines = [];
        $this->previousRawLines = [];
        $this->previousCursorPos = null;
        $this->previousWidth = -1; // -1 triggers widthChanged
        $this->previousHeight = 0;
        $this->previousViewportTop = 0;
        $this->cursorRow = 0;
        $this->hardwareCursorRow = 0;
        $this->maxLinesRendered = 0;
    }

    /**
     * Get the final cursor position for cleanup when stopping.
     *
     * @return array{line_count: int, cursor_row: int}
     */
    public function getState(): array
    {
        return [
            'line_count' => \count($this->previousLines),
            'cursor_row' => $this->hardwareCursorRow,
        ];
    }

    /**
     * @param string[]                                   $lines
     * @param array{row: int, col: int, shape: int}|null $cursorPos
     */
    private function writeInternal(array $lines, ?array $cursorPos, int $firstChanged, int $lastChanged): void
    {
        $columns = $this->terminal->getColumns();
        $rows = $this->terminal->getRows();

        $widthChanged = 0 !== $this->previousWidth && $this->previousWidth !== $columns;
        $heightChanged = 0 !== $this->previousHeight && $this->previousHeight !== $rows;

        // First render
        if ([] === $this->previousLines && !$widthChanged && !$heightChanged) {
            $this->fullRender($lines, $cursorPos, false);

            return;
        }

        // Width changes always need a full re-render because wrapping changes.
        if ($widthChanged) {
            $this->fullRender($lines, $cursorPos, true);

            return;
        }

        // Height changes need a full re-render to keep the visible viewport aligned.
        if ($heightChanged) {
            $this->fullRender($lines, $cursorPos, true);

            return;
        }

        $lineCount = \count($lines);

        if (-1 === $firstChanged) {
            $this->positionHardwareCursor($cursorPos, $lineCount);
            $this->previousHeight = $rows;

            return;
        }

        if ($firstChanged >= $lineCount) {
            $this->handleDeletedLines($lines, $cursorPos, $rows);

            return;
        }

        // previousViewportTop is the authoritative physical window base.
        $prevViewportTop = $this->previousViewportTop;

        // Differential rendering can only touch what was actually visible.
        if ($firstChanged < $prevViewportTop) {
            $this->fullRender($lines, $cursorPos, true);

            return;
        }

        $this->differentialRender($lines, $cursorPos, $firstChanged, $lastChanged, $columns, $rows, $prevViewportTop);
    }

    /**
     * @param string[]                                   $newLines
     * @param array{row: int, col: int, shape: int}|null $cursorPos
     */
    private function fullRender(array $newLines, ?array $cursorPos, bool $clear): void
    {
        $height = $this->terminal->getRows();
        $buffer = "\x1b[?2026h"; // Begin synchronized output

        if ($clear) {
            $buffer .= "\x1b[2J\x1b[3J\x1b[H"; // Clear screen, clear scrollback, and home
        }

        if ([] !== $newLines) {
            $buffer .= implode("\r\n", $newLines);
        }

        $buffer .= "\x1b[?2026l"; // End synchronized output

        $this->terminal->write($buffer);
        $this->cursorRow = max(0, \count($newLines) - 1);
        $this->hardwareCursorRow = $this->cursorRow;

        if ($clear) {
            $this->maxLinesRendered = \count($newLines);
        } else {
            $this->maxLinesRendered = max($this->maxLinesRendered, \count($newLines));
        }

        // After a full paint the visible window is the bottom |height| rows of the
        // working buffer (or 0 when content fits).
        $bufferLength = max($height, \count($newLines));
        $this->previousViewportTop = max(0, $bufferLength - $height);
        $this->previousHeight = $height;

        $this->positionHardwareCursor($cursorPos, \count($newLines));
        $this->previousLines = $newLines;
        $this->previousWidth = $this->terminal->getColumns();
    }

    /**
     * @param string[]                                   $newLines
     * @param array{row: int, col: int, shape: int}|null $cursorPos
     */
    private function handleDeletedLines(array $newLines, ?array $cursorPos, int $height): void
    {
        if (\count($this->previousLines) <= \count($newLines)) {
            $this->positionHardwareCursor($cursorPos, \count($newLines));
            $this->previousLines = $newLines;
            $this->previousWidth = $this->terminal->getColumns();
            $this->previousHeight = $height;

            return;
        }

        $prevViewportTop = $this->previousViewportTop;
        $targetRow = max(0, \count($newLines) - 1);

        // Deleted content moved the end above the previous viewport — full redraw.
        if ($targetRow < $prevViewportTop) {
            $this->fullRender($newLines, $cursorPos, true);

            return;
        }

        $buffer = "\x1b[?2026h";

        $lineDiff = $this->computeScreenLineDiff($targetRow, $prevViewportTop, $this->hardwareCursorRow);
        if ($lineDiff > 0) {
            $buffer .= "\x1b[{$lineDiff}B";
        } elseif ($lineDiff < 0) {
            $buffer .= "\x1b[".(-$lineDiff).'A';
        }

        $buffer .= "\r";

        $extraLines = \count($this->previousLines) - \count($newLines);

        if ($extraLines > $height) {
            $this->fullRender($newLines, $cursorPos, true);

            return;
        }

        $newLineCount = \count($newLines);

        if ($extraLines > 0 && $newLineCount > 0) {
            $buffer .= "\x1b[1B";
        }

        for ($i = 0; $i < $extraLines; ++$i) {
            $buffer .= "\r\x1b[2K";
            if ($i < $extraLines - 1) {
                $buffer .= "\x1b[1B";
            }
        }

        $moveUp = $extraLines + ($newLineCount > 0 ? 0 : -1);
        if ($moveUp > 0) {
            $buffer .= "\x1b[{$moveUp}A";
        }

        $buffer .= "\x1b[?2026l";

        $this->terminal->write($buffer);
        $this->cursorRow = $targetRow;
        $this->hardwareCursorRow = $targetRow;
        $this->previousHeight = $height;
        // Viewport top does not move upward on shrink (working area grows-only unless cleared).
        $this->previousViewportTop = min($prevViewportTop, max(0, max($height, \count($newLines)) - $height));

        $this->positionHardwareCursor($cursorPos, \count($newLines));
        $this->previousLines = $newLines;
        $this->previousWidth = $this->terminal->getColumns();
    }

    /**
     * @param string[]                                   $newLines
     * @param array{row: int, col: int, shape: int}|null $cursorPos
     */
    private function differentialRender(
        array $newLines,
        ?array $cursorPos,
        int $firstChanged,
        int $lastChanged,
        int $width,
        int $height,
        int $prevViewportTop,
    ): void {
        $buffer = "\x1b[?2026h"; // Begin synchronized output

        $hardwareCursorRow = $this->hardwareCursorRow;
        $prevViewportBottom = $prevViewportTop + $height - 1;

        // Pure append of new lines after previous end → start drawing from the
        // newline after the last previously rendered row.
        $appendedLines = \count($newLines) > \count($this->previousLines);
        $appendStart = $appendedLines && $firstChanged === \count($this->previousLines) && $firstChanged > 0;
        $moveTargetRow = $appendStart ? $firstChanged - 1 : $firstChanged;

        // When the update target is below the previous physical viewport bottom,
        // move to the physical bottom and emit exact CRLFs so the terminal scrolls
        // natively, advancing viewport bookkeeping.
        if ($moveTargetRow > $prevViewportBottom) {
            $currentScreenRow = max(0, min($height - 1, $hardwareCursorRow - $prevViewportTop));
            $moveToBottom = $height - 1 - $currentScreenRow;
            if ($moveToBottom > 0) {
                $buffer .= "\x1b[{$moveToBottom}B";
            }
            $scroll = $moveTargetRow - $prevViewportBottom;
            $buffer .= str_repeat("\r\n", $scroll);
            $prevViewportTop += $scroll;
            $hardwareCursorRow = $moveTargetRow;
        }

        $lineDiff = $this->computeScreenLineDiff($moveTargetRow, $prevViewportTop, $hardwareCursorRow);
        if ($lineDiff > 0) {
            $buffer .= "\x1b[{$lineDiff}B";
        } elseif ($lineDiff < 0) {
            $buffer .= "\x1b[".(-$lineDiff).'A';
        }

        $buffer .= $appendStart ? "\r\n" : "\r";

        // Render only changed lines (firstChanged..lastChanged).
        $renderEnd = min($lastChanged, \count($newLines) - 1);

        for ($i = $firstChanged; $i <= $renderEnd; ++$i) {
            if ($i > $firstChanged) {
                $buffer .= "\r\n";
            }
            $buffer .= "\x1b[2K";

            $line = $newLines[$i];
            $lineWidth = null;
            $lineLength = \strlen($line);

            if ($lineLength === strcspn($line, "\x1b\t") && $lineLength === strspn($line, self::PRINTABLE_ASCII)) {
                $lineWidth = $lineLength;
            } elseif (!AnsiUtils::containsImage($line)) {
                $lineWidth = AnsiUtils::visibleWidth($line);
            }

            if (null !== $lineWidth && $lineWidth > $width) {
                $buffer .= "\x1b[?2026l";
                $this->terminal->write($buffer);

                $this->hardwareCursorRow = $i;
                $this->previousLines = [];
                $this->previousWidth = -1;
                $this->previousHeight = 0;
                $this->previousViewportTop = 0;

                $plainLine = preg_replace('/\x1b(?:\[[0-9;]*[a-zA-Z]|\][^\x07]*\x07)/', '', $line);
                $preview = mb_substr((string) $plainLine, 0, 100);

                throw new RenderException(\sprintf("Rendered line %d exceeds terminal width (%d > %d).\nLine preview: %s%s.", $i, $lineWidth, $width, $preview, mb_strlen((string) $plainLine) > 100 ? '...' : ''), $i, $lineWidth, $width);
            }

            $buffer .= $line;
        }

        $finalCursorRow = $renderEnd;

        // Handle content size changes
        if (\count($this->previousLines) > \count($newLines)) {
            if ($renderEnd < \count($newLines) - 1) {
                $moveDown = \count($newLines) - 1 - $renderEnd;
                $buffer .= "\x1b[{$moveDown}B";
                $finalCursorRow = \count($newLines) - 1;
            }

            $extraLines = \count($this->previousLines) - \count($newLines);
            $buffer .= str_repeat("\r\n\x1b[2K", $extraLines);
            $buffer .= "\x1b[{$extraLines}A";
        } elseif (\count($newLines) > \count($this->previousLines) && $renderEnd < \count($newLines) - 1) {
            for ($i = $renderEnd + 1; $i < \count($newLines); ++$i) {
                $buffer .= "\r\n\x1b[2K";
                $buffer .= $newLines[$i];
                $finalCursorRow = $i;
            }
        }

        $buffer .= "\x1b[?2026l"; // End synchronized output

        $this->terminal->write($buffer);

        $this->cursorRow = max(0, \count($newLines) - 1);
        $this->hardwareCursorRow = $finalCursorRow;
        $this->maxLinesRendered = max($this->maxLinesRendered, \count($newLines));
        // Keep viewport top from advancing backwards; follow the bottom when content grows.
        $this->previousViewportTop = max($prevViewportTop, $finalCursorRow - $height + 1);
        $this->previousHeight = $height;

        $this->positionHardwareCursor($cursorPos, \count($newLines));
        $this->previousLines = $newLines;
        $this->previousWidth = $this->terminal->getColumns();
    }

    /**
     * Convert a logical buffer row delta into a physical screen-row delta using
     * the current viewport top.
     */
    private function computeScreenLineDiff(int $targetLogicalRow, int $viewportTop, int $hardwareCursorRow): int
    {
        $currentScreenRow = $hardwareCursorRow - $viewportTop;
        $targetScreenRow = $targetLogicalRow - $viewportTop;

        return $targetScreenRow - $currentScreenRow;
    }

    /**
     * Strip cursor markers, apply line resets, and detect changed rows in one pass.
     *
     * @param string[] $lines
     *
     * @return array{lines: string[], cursor_pos: array{row: int, col: int, shape: int}|null, first_changed: int, last_changed: int}
     */
    private function prepareLines(array $lines): array
    {
        $cursorPos = null;
        $firstChanged = -1;
        $lastChanged = -1;
        $lineCount = \count($lines);
        $previousLineCount = \count($this->previousLines);

        foreach ($lines as $row => $line) {
            if ($line === $oldLine = $row < $previousLineCount ? $this->previousLines[$row] : '') {
                continue;
            }

            if (str_contains($line, "\x1b")) {
                if ($oldLine === $line."\x1b[0m" || $oldLine === $line.AnsiUtils::SEGMENT_RESET) {
                    $lines[$row] = $oldLine;
                    continue;
                }

                if (null === $cursorPos) {
                    $markerIndex = strpos($line, AnsiUtils::CURSOR_MARKER_PREFIX);
                    if (false !== $markerIndex && false !== $endIndex = strpos($line, "\x07", $markerIndex)) {
                        $markerLen = $endIndex - $markerIndex + 1;
                        $shapeStr = substr($line, $markerIndex + \strlen(AnsiUtils::CURSOR_MARKER_PREFIX), $endIndex - $markerIndex - \strlen(AnsiUtils::CURSOR_MARKER_PREFIX));
                        $beforeMarker = substr($line, 0, $markerIndex);
                        $cursorPos = ['row' => $row, 'col' => AnsiUtils::visibleWidth($beforeMarker), 'shape' => (int) $shapeStr];
                        $line = substr($line, 0, $markerIndex).substr($line, $markerIndex + $markerLen);
                    }
                }

                if (str_contains($line, "\x1b") && !AnsiUtils::containsImage($line)) {
                    $line = str_contains($line, "\x1b]8;")
                        ? $line.AnsiUtils::SEGMENT_RESET
                        : $line."\x1b[0m";
                }
            }

            $lines[$row] = $line;

            if ($oldLine !== $line) {
                if (-1 === $firstChanged) {
                    $firstChanged = $row;
                }
                $lastChanged = $row;
            }
        }

        if ($previousLineCount > $lineCount) {
            if (-1 === $firstChanged) {
                $firstChanged = $lineCount;
            }
            $lastChanged = $previousLineCount - 1;
        }

        return [
            'lines' => $lines,
            'cursor_pos' => $cursorPos,
            'first_changed' => $firstChanged,
            'last_changed' => $lastChanged,
        ];
    }

    /**
     * Position the hardware cursor with relative row movement + absolute column.
     *
     * Absolute CUP against logical row numbers is clamped by the terminal to the
     * physical viewport once content has scrolled; relative CUU/CUD from the
     * tracked hardwareCursorRow (logical) after viewport bookkeeping stays valid.
     *
     * @param array{row: int, col: int, shape: int}|null $cursorPos
     */
    private function positionHardwareCursor(?array $cursorPos, int $totalLines): void
    {
        if (null === $cursorPos || $totalLines <= 0) {
            $this->terminal->hideCursor();

            return;
        }

        $targetRow = max(0, min($cursorPos['row'], $totalLines - 1));
        $targetCol = max(0, $cursorPos['col']);

        $buffer = '';
        $rowDelta = $targetRow - $this->hardwareCursorRow;
        if ($rowDelta > 0) {
            $buffer .= "\x1b[{$rowDelta}B";
        } elseif ($rowDelta < 0) {
            $buffer .= "\x1b[".(-$rowDelta).'A';
        }
        // CHA: absolute column (1-indexed)
        $buffer .= "\x1b[".($targetCol + 1).'G';
        // DECSCUSR cursor shape
        $buffer .= "\x1b[".$cursorPos['shape'].' q';

        $this->terminal->write($buffer);

        $this->hardwareCursorRow = $targetRow;

        if ($this->showHardwareCursor) {
            $this->terminal->showCursor();
        } else {
            $this->terminal->hideCursor();
        }
    }
}
