<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ApplyShellCommand;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\InvalidateRunContext;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class RunOrchestrator
{
    private const string ScopeStartRun = 'command.start';
    private const string ScopeApplyCommand = 'command.apply';
    private const string ScopeApplyShellCommand = 'command.apply_shell';
    private const string ScopeAdvanceRun = 'command.advance';
    private const string ScopeLlmResult = 'result.llm';
    private const string ScopeToolResult = 'result.tool';
    private const string ScopeCompactRun = 'command.compact';
    private const string ScopeCompactionResult = 'result.compaction';

    public function __construct(
        private RunMessageProcessor $runMessageProcessor,
        private ActiveRunContextInterface $activeRunContext,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * Handles StartRun message to initialize a new agent run.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onStartRun(StartRun $message): void
    {
        $this->dispatch(
            'command.start_run',
            self::ScopeStartRun,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId()],
        );
    }

    /**
     * Processes ApplyCommand message to modify run state.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyCommand(ApplyCommand $message): void
    {
        $this->dispatch(
            'command.apply',
            self::ScopeApplyCommand,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'command_kind' => $message->kind],
        );
    }

    /**
     * Processes a direct bang shell command through the locked run pipeline.
     *
     * Deliberately untraced: shell commands run outside the traced LLM/tool
     * step lifecycle and produce no span.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onApplyShellCommand(ApplyShellCommand $message): void
    {
        // Null attributes keep the shell handler untraced (no span).
        $this->dispatch(self::ScopeApplyShellCommand, self::ScopeApplyShellCommand, $message);
    }

    /**
     * Handles AdvanceRun message to trigger next step execution.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onAdvanceRun(AdvanceRun $message): void
    {
        $this->dispatch(
            'turn.orchestrator.advance',
            self::ScopeAdvanceRun,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId()],
        );
    }

    /**
     * Processes LlmStepResult message to update run state with LLM output.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onLlmStepResult(LlmStepResult $message): void
    {
        $this->dispatch(
            'turn.orchestrator.llm_result',
            self::ScopeLlmResult,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId()],
        );
    }

    /**
     * Handles ToolCallResult message to process tool execution outcomes.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onToolCallResult(ToolCallResult $message): void
    {
        $this->dispatch(
            'turn.orchestrator.tool_result',
            self::ScopeToolResult,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'tool_call_id' => $message->toolCallId],
        );
    }

    /**
     * Handles CompactRun message to initiate compaction.
     *
     * This is a run-control transition and therefore only runs on the command
     * bus; its asynchronous model work is emitted separately as
     * ExecuteCompactionStep on the execution bus.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onCompactRun(CompactRun $message): void
    {
        $this->dispatch(
            'command.compact',
            self::ScopeCompactRun,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'trigger' => $message->trigger],
        );
    }

    /**
     * Processes CompactionStepResult message to finalize compaction.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onCompactionStepResult(CompactionStepResult $message): void
    {
        $this->dispatch(
            'result.compaction',
            self::ScopeCompactionResult,
            $message,
            ['run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId()],
        );
    }

    /**
     * Handles a canonical-event side-channel notification without replaying,
     * processing, or persisting run state. The next run-control transition
     * rebuilds the invalidated process-local context from canonical events.
     */
    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function onInvalidateRunContext(InvalidateRunContext $message): void
    {
        $this->activeRunContext->invalidate($message->runId());
    }

    /**
     * Common Messenger-handler envelope: correlation log context, optional
     * root trace span, and locked pipeline processing.
     *
     * Handlers that must stay untraced (ApplyShellCommand) pass null
     * attributes; everything else is traced with its event type as span
     * name and the given attributes.
     *
     * @param ?array<string, mixed> $spanAttributes
     */
    private function dispatch(
        string $eventType,
        string $scope,
        AbstractAgentBusMessage $message,
        ?array $spanAttributes = null,
    ): void {
        $this->withLogContext($message->runId(), $eventType, function () use ($scope, $message, $eventType, $spanAttributes): void {
            $handle = fn () => $this->runMessageProcessor->process($scope, $message);

            if (null === $this->tracer || null === $spanAttributes) {
                $handle();

                return;
            }

            $this->tracer->inSpan($eventType, $spanAttributes, $handle, root: true);
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
