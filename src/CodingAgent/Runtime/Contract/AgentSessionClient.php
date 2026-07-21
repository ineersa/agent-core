<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Runtime boundary between TUI/presentation and the agent runtime.
 *
 * TUI code must only depend on this interface and the protocol DTOs.
 * It must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger.
 *
 * Two implementations exist:
 * - InProcessAgentSessionClient — calls agent-core services directly
 * - JsonlProcessAgentSessionClient — spawns a headless process and communicates over JSONL
 */
interface AgentSessionClient
{
    public function start(StartRunRequest $request): RunHandle;

    /**
     * Passively attach to an existing session/run for event polling and commands.
     *
     * Loads no new AgentCore work: must not dispatch Continue or otherwise
     * advance the run. TUI resume and session switch use this path.
     */
    public function attach(string $runId): RunHandle;

    public function send(string $runId, UserCommand $command): void;

    /**
     * @return iterable<RuntimeEvent>
     */
    public function events(string $runId): iterable;

    /**
     * Begin retaining cross-run JSONL events for a child run opened in subagent live view.
     *
     * Must be called before canonical child snapshot replay so parent-poll half-race events
     * are not lost. Pair with {@see endObservingChildRun()} on live-view exit or child switch.
     */
    public function beginObservingChildRun(string $childRunId): void;

    /**
     * Stop full observation retention for a child run; replayable durable backlog is released.
     */
    public function endObservingChildRun(string $childRunId): void;

    public function cancel(string $runId): void;

    /**
     * Execute a shell command (submitted via ! prefix) without invoking
     * the LLM. Creates a session if needed. Output is projected into the
     * transcript as tool execution events.
     *
     * @return RunHandle handle for polling events
     */
    public function shellExecute(ShellExecutionRequestDTO $request): RunHandle;

    /**
     * Mark a run as completed by emitting a terminal AgentEnd event.
     *
     * Used by standalone shell commands (first-input !cmd) to signal
     * the TUI poller that the shell-only action is finished so the
     * working status transitions from Running to Completed.
     */
    public function completeRun(string $runId): void;

    /**
     * Request compaction of the conversation for the given run.
     *
     * For active runs, the request queues until the next safe boundary
     * (after tool results or at turn start). For idle/terminal runs,
     * compaction starts immediately.
     *
     * @param string      $runId              The run to compact
     * @param string|null $customInstructions Optional custom instructions
     *                                        passed to the summarization model
     */
    public function compact(string $runId, ?string $customInstructions = null): void;
}
