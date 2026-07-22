<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

/**
 * Value object representing a tmux pane created by TmuxHarness.
 */
final readonly class TmuxPane
{
    /**
     * @param non-empty-string $session tmux session name
     * @param non-empty-string $paneId  tmux pane id (e.g. "%42")
     * @param int              $width   terminal columns
     * @param int              $height  terminal rows
     */
    public function __construct(
        public string $session,
        public string $paneId,
        public int $width,
        public int $height,
    ) {
    }
}
