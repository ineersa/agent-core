<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Terminal decorator that completes every STDOUT write before returning.
 *
 * Stock Symfony {@see Terminal::write()} issues one unchecked `fwrite` + flush.
 * ScreenWriter then treats that call as a complete frame. A short write would leave
 * a partially painted terminal until a later keypress forces another paint.
 *
 * Domain state for write-all is local and ephemeral:
 * - immutable output buffer (`$data`)
 * - byte offset into that buffer
 * - remaining byte count
 *
 * All other TerminalInterface behavior delegates to the inner Symfony Terminal.
 * Prototype note: inner cursor/clear/title helpers still use stock one-shot fwrite;
 * this slice hardens the public write() path used by ScreenWriter paints.
 */
final class CompleteWriteTerminal implements TerminalInterface
{
    public function __construct(
        private readonly TerminalInterface $inner = new Terminal(),
    ) {
    }

    public function start(callable $onInput, callable $onResize, callable $onKittyProtocolActivated): void
    {
        $this->inner->start($onInput, $onResize, $onKittyProtocolActivated);
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function write(string $data): void
    {
        $length = \strlen($data);
        if (0 === $length) {
            return;
        }

        $offset = 0;
        while ($offset < $length) {
            $remaining = $length - $offset;
            $written = fwrite(\STDOUT, substr($data, $offset));
            if (false === $written || 0 === $written) {
                throw new \RuntimeException(\sprintf('CompleteWriteTerminal failed to write STDOUT payload: wrote %d of %d bytes (offset=%d, remaining=%d).', $offset, $length, $offset, $remaining));
            }

            $offset += $written;
        }

        fflush(\STDOUT);
    }

    public function getColumns(): int
    {
        return $this->inner->getColumns();
    }

    public function getRows(): int
    {
        return $this->inner->getRows();
    }

    public function isKittyProtocolActive(): bool
    {
        return $this->inner->isKittyProtocolActive();
    }

    public function moveBy(int $lines): void
    {
        $this->inner->moveBy($lines);
    }

    public function hideCursor(): void
    {
        $this->inner->hideCursor();
    }

    public function showCursor(): void
    {
        $this->inner->showCursor();
    }

    public function clearLine(): void
    {
        $this->inner->clearLine();
    }

    public function clearFromCursor(): void
    {
        $this->inner->clearFromCursor();
    }

    public function clearScreen(): void
    {
        $this->inner->clearScreen();
    }

    public function setTitle(string $title): void
    {
        $this->inner->setTitle($title);
    }

    public function bell(): void
    {
        $this->inner->bell();
    }

    public function isVirtual(): bool
    {
        return $this->inner->isVirtual();
    }
}
