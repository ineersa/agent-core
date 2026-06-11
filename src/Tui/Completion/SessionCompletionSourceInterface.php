<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Source of session completion data for the TuiCompletion layer.
 *
 * Implementations bridge from upstream session storage (e.g.
 * HatfieldSessionStore) without leaking AppSession dependencies
 * into TuiCompletion.
 *
 * @return list<SessionCompletionRow>
 */
interface SessionCompletionSourceInterface
{
    /**
     * Return all sessions available for completion, ordered most
     * recent first.
     *
     * @return list<SessionCompletionRow>
     */
    public function listCompletionSessions(): array;
}
