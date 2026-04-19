<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunHandle;
use Ineersa\AgentCore\Domain\Run\StartRunInput;

/**
 * Defines the contract for orchestrating the lifecycle of an agent execution run, including initialization, state transitions, and termination. It provides a unified interface for controlling agent behavior through steering, follow-ups, and human-in-the-loop interactions.
 */
interface AgentRunnerInterface
{
    /**
     * Initializes a new agent run with input parameters and returns a handle.
     */
    public function start(StartRunInput $input): RunHandle;

    /**
     * Resumes execution for an existing run identified by runId.
     */
    public function continue(string $runId): void;

    /**
     * Injects a directive message into an active run to guide behavior.
     */
    public function steer(string $runId, AgentMessage $message): void;

    /**
     * Provides additional context or response to an active run.
     */
    public function followUp(string $runId, AgentMessage $message): void;

    /**
     * Terminates an active run with an optional reason.
     */
    public function cancel(string $runId, ?string $reason = null): void;

    /**
     * Submits a human response to a specific question within a run.
     */
    public function answerHuman(string $runId, string $questionId, mixed $answer): void;
}
