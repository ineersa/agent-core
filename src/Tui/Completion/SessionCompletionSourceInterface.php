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
     * Return matching numeric session IDs, ordered most recent first,
     * with at most $limit rows.
     *
     * @return list<SessionCompletionRow>
     */
    public function listCompletionSessions(string $prefix, int $limit): array;
}
