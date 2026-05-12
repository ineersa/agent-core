<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RunOrchestrator
{
    private const string ScopeStartRun = 'command.start';
    private const string ScopeApplyCommand = 'command.apply';
    private const string ScopeAdvanceRun = 'command.advance';
    private const string ScopeLlmResult = 'result.llm';
    private const string ScopeToolResult = 'result.tool';

    public function __construct(
        private RunMessageProcessor $runMessageProcessor,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * Handles StartRun message to initialize a new agent run.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onStartRun(StartRun $message): void
    {
        $handle = fn () => $this->runMessageProcessor->process(self::ScopeStartRun, $message);

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('command.start_run', [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
        ], $handle, root: true);
    }

    /**
     * Processes ApplyCommand message to modify run state.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyCommand(ApplyCommand $message): void
    {
        $handle = fn () => $this->runMessageProcessor->process(self::ScopeApplyCommand, $message);

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('command.apply', [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'command_kind' => $message->kind,
        ], $handle, root: true);
    }

    /**
     * Handles AdvanceRun message to trigger next step execution.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onAdvanceRun(AdvanceRun $message): void
    {
        $handle = fn () => $this->runMessageProcessor->process(self::ScopeAdvanceRun, $message);

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('turn.orchestrator.advance', [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
        ], $handle, root: true);
    }

    /**
     * Processes LlmStepResult message to update run state with LLM output.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onLlmStepResult(LlmStepResult $message): void
    {
        $handle = fn () => $this->runMessageProcessor->process(self::ScopeLlmResult, $message);

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('turn.orchestrator.llm_result', [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
        ], $handle, root: true);
    }

    /**
     * Handles ToolCallResult message to process tool execution outcomes.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onToolCallResult(ToolCallResult $message): void
    {
        $handle = fn () => $this->runMessageProcessor->process(self::ScopeToolResult, $message);

        if (null === $this->tracer) {
            $handle();

            return;
        }

        $this->tracer->inSpan('turn.orchestrator.tool_result', [
            'run_id' => $message->runId(),
            'turn_no' => $message->turnNo(),
            'step_id' => $message->stepId(),
            'tool_call_id' => $message->toolCallId,
        ], $handle, root: true);
    }
}
