<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\Tui\ImagePaste\ClipboardImageReaderInterface;
use Ineersa\Tui\ImagePaste\ClipboardImageReadPollResultDTO;
use Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO;
use Ineersa\Tui\ImagePaste\ClipboardImageReadStartResultDTO;

/** Defers terminal result until pollCount reaches delayPolls (simulates slow clipboard). */
final class DelayedFakeClipboardImageReader implements ClipboardImageReaderInterface
{
    private bool $reading = false;
    private int $polls = 0;

    public function __construct(
        private readonly ClipboardImageReadResultDTO $terminal,
        private readonly int $delayPolls = 5,
    ) {
    }

    public function isReading(): bool
    {
        return $this->reading;
    }

    public function startRead(): ClipboardImageReadStartResultDTO
    {
        if ($this->reading) {
            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::failed('A clipboard image read is already in progress.'),
            );
        }

        $this->reading = true;
        $this->polls = 0;

        return ClipboardImageReadStartResultDTO::started();
    }

    public function poll(): ClipboardImageReadPollResultDTO
    {
        if (!$this->reading) {
            return ClipboardImageReadPollResultDTO::pending();
        }

        ++$this->polls;
        if ($this->polls < $this->delayPolls) {
            return ClipboardImageReadPollResultDTO::pending();
        }

        $this->reading = false;

        return ClipboardImageReadPollResultDTO::terminal($this->terminal);
    }

    public function cancel(): void
    {
        $this->reading = false;
        $this->polls = 0;
    }
}
