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

    public function resume(string $runId): RunHandle;

    public function send(string $runId, UserCommand $command): void;

    /**
     * @return iterable<RuntimeEvent>
     */
    public function events(string $runId): iterable;

    public function cancel(string $runId): void;

    /**
     * Execute a shell command (submitted via ! prefix) without invoking
     * the LLM. Creates a session if needed. Output is projected into the
     * transcript as tool execution events.
     *
     * @return RunHandle handle for polling events
     */
    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle;
}
