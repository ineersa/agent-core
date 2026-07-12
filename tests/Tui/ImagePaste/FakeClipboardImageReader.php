<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\Tui\ImagePaste\ClipboardImageReaderInterface;
use Ineersa\Tui\ImagePaste\ClipboardImageReadPollResultDTO;
use Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO;
use Ineersa\Tui\ImagePaste\ClipboardImageReadStartResultDTO;

/** Synchronous test double: start() completes on the next poll(). */
final class FakeClipboardImageReader implements ClipboardImageReaderInterface
{
    private ?ClipboardImageReadResultDTO $pendingTerminal = null;

    public function __construct(
        private ?ClipboardImageReadResultDTO $result = null,
    ) {
    }

    public function isReading(): bool
    {
        return null !== $this->pendingTerminal;
    }

    public function startRead(): ClipboardImageReadStartResultDTO
    {
        if ($this->isReading()) {
            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::failed('A clipboard image read is already in progress.'),
            );
        }

        $this->pendingTerminal = $this->result ?? ClipboardImageReadResultDTO::unavailable('test unavailable');

        return ClipboardImageReadStartResultDTO::started();
    }

    public function poll(): ClipboardImageReadPollResultDTO
    {
        if (null === $this->pendingTerminal) {
            return ClipboardImageReadPollResultDTO::pending();
        }

        $terminal = $this->pendingTerminal;
        $this->pendingTerminal = null;

        return ClipboardImageReadPollResultDTO::terminal($terminal);
    }

    public function cancel(): void
    {
        $this->pendingTerminal = null;
    }
}
