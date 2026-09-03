<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Finite physical viewport terminal for ScreenWriter bookkeeping tests.
 *
 * Unlike {@see \Symfony\Component\Tui\Terminal\VirtualTerminal}, this emulator
 * clamps the cursor to the visible rows, scrolls on newline at the bottom row,
 * and reports {@see isVirtual()} as false so write targeting matches a real TTY.
 *
 * @internal
 */
final class PhysicalViewportTerminal implements TerminalInterface
{
    /** @var list<string> */
    private array $rows;

    private int $cursorRow = 0;

    private int $cursorCol = 0;

    private bool $cursorVisible = true;

    public function __construct(
        private int $columns = 80,
        private int $height = 24,
    ) {
        $this->rows = array_fill(0, $this->height, str_repeat(' ', $this->columns));
    }

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
    }

    public function stop(): void
    {
    }

    public function write(string $data): void
    {
        $length = \strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            if ("\x1b" === $data[$i]) {
                $i = $this->consumeEscape($data, $i, $length);
                continue;
            }

            if ("\r" === $data[$i]) {
                $this->cursorCol = 0;
                continue;
            }

            if ("\n" === $data[$i]) {
                $this->newline();
                continue;
            }

            if ("\x07" === $data[$i]) {
                continue;
            }

            $this->putChar($data[$i]);
        }
    }

    public function getColumns(): int
    {
        return $this->columns;
    }

    public function getRows(): int
    {
        return $this->height;
    }

    public function isKittyProtocolActive(): bool
    {
        return false;
    }

    public function moveBy(int $lines): void
    {
        if ($lines > 0) {
            $this->write("\x1b[{$lines}B");
        } elseif ($lines < 0) {
            $this->write("\x1b[".(-$lines).'A');
        }
    }

    public function hideCursor(): void
    {
        $this->cursorVisible = false;
    }

    public function showCursor(): void
    {
        $this->cursorVisible = true;
    }

    public function clearLine(): void
    {
        $this->write("\x1b[2K");
    }

    public function clearFromCursor(): void
    {
        $this->write("\x1b[0J");
    }

    public function clearScreen(): void
    {
        $this->write("\x1b[2J\x1b[H");
    }

    public function setTitle(string $title): void
    {
    }

    public function bell(): void
    {
    }

    public function isVirtual(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function visibleRows(): array
    {
        return $this->rows;
    }

    public function isCursorVisible(): bool
    {
        return $this->cursorVisible;
    }

    private function consumeEscape(string $data, int $i, int $length): int
    {
        if ($i + 1 >= $length) {
            return $i;
        }

        $next = $data[$i + 1];
        if ('[' === $next) {
            return $this->consumeCsi($data, $i + 2, $length);
        }

        if (']' === $next) {
            // OSC ... BEL or ST — skip without interpreting payload.
            for ($j = $i + 2; $j < $length; ++$j) {
                if ("\x07" === $data[$j]) {
                    return $j;
                }
                if ("\x1b" === $data[$j] && $j + 1 < $length && '\\' === $data[$j + 1]) {
                    return $j + 1;
                }
            }

            return $length - 1;
        }

        // Unsupported single-character escape: skip the introducer.
        return $i + 1;
    }

    private function consumeCsi(string $data, int $start, int $length): int
    {
        $params = '';
        $j = $start;
        while ($j < $length) {
            $ch = $data[$j];
            if (($ch >= '0' && $ch <= '9') || ';' === $ch || '?' === $ch || '=' === $ch || '>' === $ch || '<' === $ch) {
                $params .= $ch;
                ++$j;
                continue;
            }

            $this->applyCsi($params, $ch);

            return $j;
        }

        return $length - 1;
    }

    private function applyCsi(string $params, string $final): void
    {
        if ('h' === $final || 'l' === $final) {
            // DEC private modes such as synchronized output — ignore.
            return;
        }

        if ('A' === $final) {
            $n = $this->csiParam($params, 1);
            $this->cursorRow = max(0, $this->cursorRow - $n);

            return;
        }

        if ('B' === $final) {
            $n = $this->csiParam($params, 1);
            $this->cursorRow = min($this->height - 1, $this->cursorRow + $n);

            return;
        }

        if ('C' === $final) {
            $n = $this->csiParam($params, 1);
            $this->cursorCol = min($this->columns - 1, $this->cursorCol + $n);

            return;
        }

        if ('D' === $final) {
            $n = $this->csiParam($params, 1);
            $this->cursorCol = max(0, $this->cursorCol - $n);

            return;
        }

        if ('G' === $final) {
            $n = $this->csiParam($params, 1);
            $this->cursorCol = max(0, min($this->columns - 1, $n - 1));

            return;
        }

        if ('H' === $final || 'f' === $final) {
            $parts = '' === $params ? [] : explode(';', $params);
            $row = max(1, (int) ($parts[0] ?? 1));
            $col = max(1, (int) ($parts[1] ?? 1));
            $this->cursorRow = max(0, min($this->height - 1, $row - 1));
            $this->cursorCol = max(0, min($this->columns - 1, $col - 1));

            return;
        }

        if ('J' === $final) {
            $mode = $this->csiParam($params, 0);
            if (2 === $mode || 3 === $mode) {
                $this->rows = array_fill(0, $this->height, str_repeat(' ', $this->columns));
                if (2 === $mode) {
                    $this->cursorRow = 0;
                    $this->cursorCol = 0;
                }
            } elseif (0 === $mode) {
                $this->clearFromCursorToEnd();
            }

            return;
        }

        if ('K' === $final) {
            $mode = $this->csiParam($params, 0);
            $row = $this->rows[$this->cursorRow];
            if (2 === $mode) {
                $this->rows[$this->cursorRow] = str_repeat(' ', $this->columns);
            } elseif (0 === $mode) {
                $this->rows[$this->cursorRow] = substr($row, 0, $this->cursorCol).str_repeat(' ', $this->columns - $this->cursorCol);
            } elseif (1 === $mode) {
                $this->rows[$this->cursorRow] = str_repeat(' ', $this->cursorCol + 1).substr($row, $this->cursorCol + 1);
            }

            return;
        }

        if ('q' === $final) {
            // Cursor shape — ignore.
            return;
        }
    }

    private function csiParam(string $params, int $default): int
    {
        if ('' === $params || '?' === $params[0]) {
            return $default;
        }

        $parts = explode(';', $params);

        return max(0, (int) ($parts[0] ?: $default));
    }

    private function putChar(string $ch): void
    {
        if ($this->cursorCol >= $this->columns) {
            $this->newline();
            $this->cursorCol = 0;
        }

        $row = $this->rows[$this->cursorRow];
        $this->rows[$this->cursorRow] = substr($row, 0, $this->cursorCol).$ch.substr($row, $this->cursorCol + 1);
        ++$this->cursorCol;
    }

    private function newline(): void
    {
        if ($this->cursorRow >= $this->height - 1) {
            array_shift($this->rows);
            $this->rows[] = str_repeat(' ', $this->columns);
            $this->cursorRow = $this->height - 1;
        } else {
            ++$this->cursorRow;
        }
        $this->cursorCol = 0;
    }

    private function clearFromCursorToEnd(): void
    {
        $row = $this->rows[$this->cursorRow];
        $this->rows[$this->cursorRow] = substr($row, 0, $this->cursorCol).str_repeat(' ', $this->columns - $this->cursorCol);
        for ($r = $this->cursorRow + 1; $r < $this->height; ++$r) {
            $this->rows[$r] = str_repeat(' ', $this->columns);
        }
    }
}
