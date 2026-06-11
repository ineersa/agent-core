<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Completion\SessionCompletionRow;
use Ineersa\Tui\Completion\SessionCompletionSourceInterface;

/**
 * Adapter from HatfieldSessionStore to SessionCompletionSourceInterface.
 *
 * Lives in TuiListener because TuiListener is permitted to depend on
 * both AppSession (HatfieldSessionStore) and TuiCompletion
 * (SessionCompletionSourceInterface), keeping the TuiCompletion layer
 * free of AppSession dependencies.
 */
final readonly class SessionCompletionSource implements SessionCompletionSourceInterface
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
    ) {
    }

    /**
     * @return list<SessionCompletionRow>
     */
    public function listCompletionSessions(): array
    {
        $rows = [];
        foreach ($this->sessionStore->listSessions() as $session) {
            $rows[] = new SessionCompletionRow(
                sessionId: $session['sessionId'],
                displayTitle: $session['displayTitle'],
            );
        }

        return $rows;
    }
}
