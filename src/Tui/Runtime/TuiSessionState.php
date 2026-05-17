<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Transcript\TranscriptEntry;

/**
 * Mutable state bag for the interactive TUI session.
 *
 * Replaces the previous pattern of 6+ variables captured by reference (&)
 * across anonymous closures. All listeners share a single $state instance,
 * making the control flow explicit and the listeners testable.
 *
 * Transcript entries are stored as plain model objects; rendering
 * (theme colors, prefixes) is applied by ChatScreen at display time.
 */
final class TuiSessionState
{
    public string $sessionId;
    public bool $resuming;

    public ?RunHandle $handle = null;
    public ?StartRunRequest $request = null;

    /** @var list<TranscriptEntry> Transcript entries (plain, un-themed) */
    public array $transcript = [];

    public int $lastSeq = 0;
    public float $lastPoll = 0.0;

    public function __construct(
        string $sessionId,
        bool $resuming = false,
    ) {
        $this->sessionId = $sessionId;
        $this->resuming = $resuming;
    }
}
