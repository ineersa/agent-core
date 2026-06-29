<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Runtime\TuiSessionState;
use Symfony\Component\Tui\Tui;

/**
 * TUI-level session switch lifecycle contract.
 *
 * Defines the narrow set of operations that TuiRuntimeContext and
 * future TUI commands/services need to request session switches
 * without importing concrete Application layer services.
 *
 * One implementation (TuiSessionSwitchService) fulfills this
 * contract; TUI runtime code types against this interface only.
 */
interface TuiSessionSwitchServiceInterface
{
    /**
     * Bind per-iteration references before the event loop starts.
     */
    public function bindForIteration(
        Tui $tui,
        AgentSessionClient $client,
        TuiSessionState $state,
    ): void;

    /**
     * Request a switch to an existing session by ID.
     */
    public function requestResume(string $sessionId): void;

    /**
     * Request a switch to a fresh draft session.
     */
    public function requestNewDraft(?StartRunRequest $request = null): void;

    /**
     * Rewind the current session to a target turn (in-session rewind).
     *
     * Cancels the current run, then dispatches a rewind_to_turn command
     * via AgentSessionClient::send(). The controller subprocess appends
     * a LeafSet event, rebuilds RunState, and emits RunLeafChanged.
     *
     * Unlike requestResume/requestNewDraft, this does NOT stop the event
     * loop — the rewind is observed reactively via RuntimeEventPoller.
     *
     * @throws \RuntimeException if there is no active session or run handle
     */
    public function rewindToTurn(int $targetTurnNo): void;

    /**
     * True when a pending switch has been requested but not yet consumed.
     */
    public function hasPendingSwitch(): bool;
}
