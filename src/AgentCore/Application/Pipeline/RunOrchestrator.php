<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RunOrchestrator
{
    private const string ScopeStartRun = 'command.start';
    private const string ScopeApplyCommand = 'command.apply';
    private const string ScopeAdvanceRun = 'command.advance';
    private const string ScopeLlmResult = 'result.llm';
    private const string ScopeToolResult = 'result.tool';
    private const string ScopeCompactRun = 'command.compact';
    private const string ScopeCompactionResult = 'result.compaction';

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
        $this->withLogContext($message->runId(), 'command.start_run', function () use ($message): void {
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
        });
    }

    /**
     * Processes ApplyCommand message to modify run state.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyCommand(ApplyCommand $message): void
    {
        $this->withLogContext($message->runId(), 'command.apply', function () use ($message): void {
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
        });
    }

    /**
     * Handles AdvanceRun message to trigger next step execution.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onAdvanceRun(AdvanceRun $message): void
    {
        $this->withLogContext($message->runId(), 'turn.orchestrator.advance', function () use ($message): void {
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
        });
    }

    /**
     * Processes LlmStepResult message to update run state with LLM output.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onLlmStepResult(LlmStepResult $message): void
    {
        $this->withLogContext($message->runId(), 'turn.orchestrator.llm_result', function () use ($message): void {
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
        });
    }

    /**
     * Handles ToolCallResult message to process tool execution outcomes.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onToolCallResult(ToolCallResult $message): void
    {
        $this->withLogContext($message->runId(), 'turn.orchestrator.tool_result', function () use ($message): void {
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
        });
    }

    /**
     * Handles CompactRun message to initiate compaction.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onCompactRun(CompactRun $message): void
    {
        $this->withLogContext($message->runId(), 'command.compact', function () use ($message): void {
            $handle = fn () => $this->runMessageProcessor->process(self::ScopeCompactRun, $message);

            if (null === $this->tracer) {
                $handle();

                return;
            }

            $this->tracer->inSpan('command.compact', [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
                'trigger' => $message->trigger,
            ], $handle, root: true);
        });
    }

    /**
     * Processes CompactionStepResult message to finalize compaction.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onCompactionStepResult(CompactionStepResult $message): void
    {
        $this->withLogContext($message->runId(), 'result.compaction', function () use ($message): void {
            $handle = fn () => $this->runMessageProcessor->process(self::ScopeCompactionResult, $message);

            if (null === $this->tracer) {
                $handle();

                return;
            }

            $this->tracer->inSpan('result.compaction', [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
            ], $handle, root: true);
        });
    }

    /**
     * Wrap an operation in RunLogContext with the run's correlation fields
     * so every log emitted within the scope carries run_id and event_type.
     */
    private function withLogContext(string $runId, string $eventType, callable $operation): void
    {
        RunLogContext::enter([
            'run_id' => $runId,
            'session_id' => $runId,
            'event_type' => $eventType,
            'component' => 'runtime',
        ]);

        try {
            $operation();
        } finally {
            RunLogContext::leave();
        }
    }
}
