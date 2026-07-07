<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildArtifactLaunchContextStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Uid\Uuid;

/**
 * Launches and supervises fork child runs (main-agent child with inherited history).
 *
 * Launches fork children in the background: the fork tool returns immediately after
 * start(). Parent subagent_progress and terminal finalization are handled by
 * ChildArtifactCompletionPoller in the controller process.
 */
final class ForkExecutionService
{
    private const string FORK_AGENT_NAME = 'fork';

    public function __construct(
        private readonly ForkContextBuilder $forkContextBuilder,
        private readonly ForkChildMessageComposer $messageComposer,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildArtifactLaunchContextStore $launchContextStore,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $runStore,
        private readonly RunStoreInterface $parentRunStore,
        private readonly EventStoreInterface $eventStore,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AgentMcpToolsResolver $mcpToolsResolver,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private readonly SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?ForkLevelEnum $requestedLevel = null,
    ): string {
        $task = trim($task);
        if ('' === $task) {
            throw new ToolCallException('The fork tool requires a non-empty task.', retryable: false);
        }

        if ($this->metadataReader->isAgentChild($parentRunId)) {
            throw new ToolCallException('Nested fork launches are not supported. Fork children cannot launch another fork.', retryable: false);
        }

        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState) {
            throw new ToolCallException('Fork tool requires an active parent run with persisted state.', retryable: false);
        }

        $snapshot = $this->forkContextBuilder->build(
            parentMessages: $parentState->messages,
            task: $task,
            requestedLevel: $requestedLevel,
        );

        $policy = $this->resolveForkToolPolicy($parentRunId);
        $allowedTools = $policy['tools'];

        $artifactId = 'agent_'.bin2hex(random_bytes(8));
        $agentRunId = Uuid::v4()->toRfc4122();

        $entry = $this->artifactRegistry->create(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: self::FORK_AGENT_NAME,
            kind: AgentArtifactKindEnum::Fork,
        );
        $this->childRunDirectory->register($entry);

        $agentsMd = $this->extractUserContextBySource($parentRunId, 'agents_context');
        $skillsContext = $this->skillsContextBuilder->build();
        $agentsContext = $this->agentsContextBuilder->build();

        $composed = $this->messageComposer->compose(
            snapshot: $snapshot,
            artifactId: $artifactId,
            allowedToolNames: $allowedTools,
            agentsMd: $agentsMd,
            skillsContext: $skillsContext,
            agentsContext: $agentsContext,
        );

        $resolvedModel = $snapshot->resolvedModel ?? $this->resolveSessionModelFallback($parentRunId);

        $childMetadata = new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'child_kind' => 'fork',
                'parent_run_id' => $parentRunId,
                'agent_name' => self::FORK_AGENT_NAME,
                'artifact_id' => $artifactId,
                'interactive' => true,
                'fork_level' => $snapshot->level->value,
            ],
            model: $resolvedModel,
            reasoning: null,
            toolsScope: [
                'allowed_tools' => $allowedTools,
                'mcp' => $policy['mcp'],
            ],
        );

        $this->agentRunner->start(new StartRunInput(
            systemPrompt: $composed['systemPrompt'],
            messages: $composed['messages'],
            runId: $agentRunId,
            metadata: $childMetadata,
        ));

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: new \DateTimeImmutable(),
        );

        $progressStartedMicros = $this->nowMicros();
        $this->recordLaunchContext(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            taskSummary: $task,
            resolvedModel: $resolvedModel,
            progressStartedMicros: $progressStartedMicros,
        );

        $childState = $this->runStore->get($agentRunId);
        if (null !== $childState) {
            $progressSeq = $this->resolveNextProgressSeq($parentRunId);
            $this->emitRunningProgress(
                parentRunId: $parentRunId,
                agentRunId: $agentRunId,
                artifactId: $artifactId,
                taskSummary: $task,
                model: $resolvedModel,
                state: $childState,
                seq: $progressSeq,
                progressStartedMicros: $progressStartedMicros,
                progressStatus: 'running',
            );
            $this->advanceParentSequence($parentRunId, $progressSeq);
        }

        return $this->buildLaunchResult(
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            level: $snapshot->level->value,
            resolvedModel: $resolvedModel,
        );
    }

    private function buildLaunchResult(string $artifactId, string $agentRunId, string $level, ?string $resolvedModel): string
    {
        $modelLine = null !== $resolvedModel && '' !== trim($resolvedModel)
            ? $resolvedModel
            : '(session default)';

        return \sprintf(
            "Fork launched in the background.\n- artifact_id: %s\n- agent_run_id: %s\n- level: %s\n- model: %s\nUse /agents-live to monitor progress. Use agent_retrieve(agent_run_id=\"%s\") for handoff/metadata/events/history when complete.",
            $artifactId,
            $agentRunId,
            $level,
            $modelLine,
            $agentRunId,
        );
    }

    private function recordLaunchContext(
        string $parentRunId,
        string $artifactId,
        string $taskSummary,
        ?string $resolvedModel,
        int $progressStartedMicros,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            throw new ToolCallException('Fork tool requires an active parent run context to record launch metadata.', retryable: false);
        }

        $this->launchContextStore->write($parentRunId, $artifactId, [
            'parent_tool_call_id' => $context->toolCallId(),
            'parent_turn_no' => $context->turnNo(),
            'parent_tool_name' => $context->toolName(),
            'task_summary' => $taskSummary,
            'agent_name' => self::FORK_AGENT_NAME,
            'resolved_model' => $resolvedModel,
            'progress_started_micros' => $progressStartedMicros,
        ]);
    }

    /**
     * @return array{tools: list<string>, mcp: array{mode: string, tools: list<string>}}
     */
    private function resolveForkToolPolicy(string $parentRunId): array
    {
        $tools = $this->toolRegistry->activeToolNames();
        $tools = array_values(array_filter(
            $tools,
            static fn (string $name): bool => 'fork' !== $name,
        ));

        $mcpResolved = $this->mcpToolsResolver->resolve(null, $parentRunId);
        foreach ($mcpResolved['mcp_runtime_tools'] as $mcpTool) {
            if (!\in_array($mcpTool, $tools, true)) {
                $tools[] = $mcpTool;
            }
        }

        return [
            'tools' => $tools,
            'mcp' => $mcpResolved['mcp_policy'],
        ];
    }

    private function resolveSessionModelFallback(string $parentRunId): ?string
    {
        $metadata = $this->metadataReader->readRunStartedMetadata($parentRunId);
        if (null === $metadata) {
            return null;
        }

        $model = $metadata['model'] ?? null;

        return \is_string($model) && '' !== trim($model) ? $model : null;
    }

    private function emitRunningProgress(
        string $parentRunId,
        string $agentRunId,
        string $artifactId,
        string $taskSummary,
        ?string $model,
        RunState $state,
        int $seq,
        int $progressStartedMicros,
        string $progressStatus,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $elapsedMs = (int) (($this->nowMicros() - $progressStartedMicros) / 1000);
        $enrichment = $this->childProgressSummaryBuilder->summarize(
            $parentRunId,
            $agentRunId,
            $artifactId,
            $state,
            $model,
        );
        $progress = $this->progressSnapshotBuilder->singleRunning(
            self::FORK_AGENT_NAME,
            $artifactId,
            $agentRunId,
            $taskSummary,
            $state,
            $elapsedMs,
            $enrichment,
            $progressStatus,
        );

        $this->appendProgressEvent($parentRunId, $context, $seq, $progress);
    }


    /**
     * @param array<string, mixed> $progress
     */
    private function appendProgressEvent(string $parentRunId, \Ineersa\AgentCore\Application\Tool\ToolContext $context, int $seq, array $progress): void
    {
        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $context->turnNo(),
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $context->toolCallId(),
                'tool_name' => $context->toolName(),
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => $context->orderIndex(),
            ],
        );

        $this->eventStore->append($event);
    }

    private function resolveNextProgressSeq(string $parentRunId): int
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        $stateLastSeq = null !== $parentState ? $parentState->lastSeq : 0;
        $maxEventSeq = 0;
        foreach ($this->eventStore->allFor($parentRunId) as $event) {
            if ($event->seq > $maxEventSeq) {
                $maxEventSeq = $event->seq;
            }
        }

        return max($stateLastSeq, $maxEventSeq) + 1;
    }

    private function advanceParentSequence(string $parentRunId, int $seq): void
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState || $parentState->lastSeq >= $seq) {
            return;
        }

        $nextState = new RunState(
            runId: $parentState->runId,
            status: $parentState->status,
            version: $parentState->version + 1,
            turnNo: $parentState->turnNo,
            lastSeq: $seq,
            isStreaming: $parentState->isStreaming,
            streamingMessage: $parentState->streamingMessage,
            pendingToolCalls: $parentState->pendingToolCalls,
            errorMessage: $parentState->errorMessage,
            messages: $parentState->messages,
            activeStepId: $parentState->activeStepId,
            retryableFailure: $parentState->retryableFailure,
        );

        $this->parentRunStore->compareAndSwap($nextState, $parentState->version);
    }

    private function extractUserContextBySource(string $parentRunId, string $source): string
    {
        $state = $this->parentRunStore->get($parentRunId);
        if (null === $state) {
            return '';
        }

        foreach ($state->messages as $message) {
            if ('user-context' !== $message->role) {
                continue;
            }
            if ($source !== ($message->metadata['source'] ?? null)) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    return (string) $block['text'];
                }
            }
        }

        return '';
    }

    private function nowMicros(): int
    {
        $instant = $this->clock->now();
        $seconds = (int) $instant->format('U');
        $micro = (int) $instant->format('u');

        return ($seconds * 1_000_000) + $micro;
    }

}
