<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

use Symfony\Component\Tui\Tui;

/**
 * Prototype that coalesces rapid ordinary paint requests into the latest frame.
 *
 * The local state is one pending render, a sticky forced-render flag, and the
 * monotonic time of the last emitted frame. Runtime polling and input keep their
 * existing cadence. Forced renders remain immediate.
 */
final class FrameCoalescingTui extends Tui
{
    private const int FRAME_INTERVAL_NS = 100_000_000;

    private bool $pending = false;

    private bool $forceNext = false;

    private ?int $lastRenderedAtNs = null;

    public function requestRender(bool $force = false): void
    {
        $this->pending = true;
        $this->forceNext = $this->forceNext || $force;

        // Keep Symfony's private renderRequested flag set while a frame waits.
        parent::requestRender($force);
    }

    public function processRender(): void
    {
        if (!$this->pending) {
            parent::processRender();

            return;
        }

        $now = hrtime(true);
        if (!$this->forceNext
            && null !== $this->lastRenderedAtNs
            && ($now - $this->lastRenderedAtNs) < self::FRAME_INTERVAL_NS) {
            return;
        }

        $this->pending = false;
        $this->forceNext = false;
        $this->lastRenderedAtNs = $now;

        parent::processRender();
    }
}
