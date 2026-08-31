<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;

/**
 * TUI-level session switch lifecycle contract.
 *
 * Defines the narrow set of operations that TuiRuntimeContext and
 * future TUI commands/services need to request session switches
 * without importing concrete Application layer services.
 *
 * One implementation (TuiSessionSwitchService) fulfills this
 * contract; TUI runtime code types against this interface only.
 * The implementation is constructed per session iteration with the
 * iteration's Tui, AgentSessionClient, and TuiSessionState.
 */
interface TuiSessionSwitchServiceInterface
{
    /**
     * Request a switch to an existing session by ID.
     */
    public function requestResume(string $sessionId): void;

    /**
     * Request a switch to a fresh draft session.
     */
    public function requestNewDraft(?StartRunRequest $request = null): void;

    /**
     * Request a full-process settings reload (/reload) for the current session.
     *
     * Unlike requestResume/requestNewDraft this is NOT a same-process session
     * switch: the caller must have verified the run is idle/terminal and no
     * transient input state would be lost. The TUI event loop is stopped and
     * InteractiveMode hands the intent to the outer bin/console bootstrap loop.
     */
    public function requestReload(string $sessionId): void;

    /**
     * Select a user-prompt turn in linear history (linear history selection).
     *
     * Cancels the current run, then dispatches a select_history_turn command
     * via AgentSessionClient::send(). The controller subprocess appends
     * a history_position_set event, rebuilds RunState, and emits run.history_position_changed.
     *
     * Unlike requestResume/requestNewDraft, this does NOT stop the event
     * loop — the history position change is observed reactively via RuntimeEventPoller.
     *
     * @throws \RuntimeException if there is no active session or run handle
     */
    public function selectHistoryTurn(int $targetTurnNo): void;

    }
