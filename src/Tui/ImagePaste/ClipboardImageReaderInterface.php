<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

/**
 * Non-blocking OS clipboard image capture for the TUI event loop.
 *
 * start() returns promptly after launching the helper process; poll() performs
 * at most one incremental drain/timeout check. Never call poll() from InputEvent
 * handlers in a loop — drive poll() from TuiTickDispatcher instead.
 */
interface ClipboardImageReaderInterface
{
    public function isReading(): bool;

    /**
     * Begin reading image bytes when a backend is available.
     */
    public function startRead(): ClipboardImageReadStartResultDTO;

    /**
     * Advance an in-flight read by one step. No-op when idle.
     */
    public function poll(): ClipboardImageReadPollResultDTO;

    /**
     * Stop an in-flight read and delete any partial temp file.
     */
    public function cancel(): void;
}
