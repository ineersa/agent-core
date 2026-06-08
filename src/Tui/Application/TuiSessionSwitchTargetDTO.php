<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;

/**
 * Carries a pending session switch target from SessionSwitchService
 * to InteractiveMode after the TUI event loop exits.
 *
 * Consumed once per loop iteration by InteractiveMode, which uses it
 * to determine the next session to initialise (resume an existing
 * session or start a fresh draft) before rebuilding TUI objects.
 */
final readonly class TuiSessionSwitchTargetDTO
{
    /**
     * @param bool                 $isDraft   True: start a fresh draft (lazy session creation).
     *                                        False: resume an existing session.
     * @param string|null          $sessionId session ID to resume (non-null when $isDraft is false)
     * @param StartRunRequest|null $request   optional initial request for a fresh draft
     */
    public function __construct(
        public bool $isDraft,
        public ?string $sessionId,
        public ?StartRunRequest $request,
    ) {
    }
}
