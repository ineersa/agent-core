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
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkChildMessageComposer;
use Ineersa\CodingAgent\Agent\Fork\ForkContextBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Uid\Uuid;

/**
 * Launches and supervises fork child runs (main-agent child with inherited history).
 *
 * MVP: blocks the fork tool call until the child reaches a terminal state, mirroring
 * foreground subagent semantics. Progress is emitted via parent subagent_progress events.
 */
final class ForkExecutionService
{
    private const int DEFAULT_POLL_MICROS = 250_000;
    private const string FORK_AGENT_NAME = 'fork';

    public function __construct(
        private readonly ForkContextBuilder $forkContextBuilder,
        private readonly ForkChildMessageComposer $messageComposer,
        private readonly AgentArtifactRegistry $artifactRegistry,
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
        private readonly AgentsConfig $agentsConfig,
        private readonly ModelResolver $modelResolver,
        private readonly SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private readonly SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private readonly ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
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

        $parentSessionModel = $this->resolveSessionModelFallback($parentRunId);

        $snapshot = $this->forkContextBuilder->build(
            parentMessages: $parentState->messages,
            task: $task,
            activeSessionModel: $parentSessionModel,
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

        $resolvedModel = $this->resolveChildModel(
            explicitModel: $modelOverride,
            snapshotModel: $snapshot->resolvedModel,
            parentRunId: $parentRunId,
        );
        $resolvedReasoning = $this->resolveChildReasoning(
            explicitReasoning: $reasoningOverride,
            snapshotThinkingLevel: $snapshot->resolvedThinkingLevel,
            parentRunId: $parentRunId,
        );

        $childMetadata = new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'child_kind' => 'fork',
                'parent_run_id' => $parentRunId,
                'agent_name' => self::FORK_AGENT_NAME,
                'artifact_id' => $artifactId,
                'interactive' => true,
            ],
            model: $resolvedModel,
            reasoning: $resolvedReasoning,
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

        return $this->pollUntilTerminal(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $task,
            resolvedModel: $resolvedModel,
        );
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
        if (null !== $metadata) {
            $model = $metadata['model'] ?? null;
            if (\is_string($model) && '' !== trim($model)) {
                return $model;
            }
        }

        return $this->modelResolver->getCurrentModel($parentRunId)?->toString();
    }

    private function resolveChildModel(?string $explicitModel, ?string $snapshotModel, string $parentRunId): ?string
    {
        if (null !== $explicitModel && '' !== trim($explicitModel)) {
            return $explicitModel;
        }

        if (null !== $snapshotModel && '' !== trim($snapshotModel)) {
            return $snapshotModel;
        }

        return $this->resolveSessionModelFallback($parentRunId);
    }

    private function resolveChildReasoning(?string $explicitReasoning, ?string $snapshotThinkingLevel, string $parentRunId): ?string
    {
        if (null !== $explicitReasoning && '' !== trim($explicitReasoning)) {
            return $explicitReasoning;
        }

        if (null !== $snapshotThinkingLevel && '' !== trim($snapshotThinkingLevel)) {
            return $snapshotThinkingLevel;
        }

        return $this->resolveSessionReasoningFallback($parentRunId);
    }

    private function resolveSessionReasoningFallback(string $parentRunId): ?string
    {
        $metadata = $this->metadataReader->readRunStartedMetadata($parentRunId);
        if (null === $metadata) {
            return null;
        }

        $reasoning = $metadata['reasoning'] ?? null;

        return \is_string($reasoning) && '' !== trim($reasoning) ? $reasoning : null;
    }

    private function pollUntilTerminal(
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        ?string $resolvedModel,
    ): string {
        $timeoutSeconds = $this->agentsConfig->subagentToolTimeoutSeconds;
        $deadline = $this->nowMicros() + $timeoutSeconds * 1_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();
        $progressSeq = $this->resolveNextProgressSeq($parentRunId);
        $progressStartedMicros = $this->nowMicros();
        $lastSignature = null;

        while (true) {
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                $this->agentRunner->cancel($agentRunId, 'Parent run cancelled fork tool.');
                $this->finalizeArtifact(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Cancelled,
                    summary: 'Cancelled by parent run.',
                );
                $this->emitTerminalProgress($parentRunId, $agentRunId, $artifactId, $taskSummary, $resolvedModel, $this->runStore->get($agentRunId), 'cancelled', $progressSeq, $progressStartedMicros);
                $this->advanceParentSequence($parentRunId, $progressSeq);

                throw new ToolCallException('Fork cancelled by parent run. Artifact: '.$artifactId, retryable: false);
            }

            if ($this->nowMicros() > $deadline) {
                $this->agentRunner->cancel($agentRunId, 'Fork timed out.');
                $this->finalizeArtifact(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Failed,
                    failureReason: 'Child run timed out.',
                    summary: 'Timed out after '.$timeoutSeconds.'s.',
                );
                $this->emitTerminalProgress($parentRunId, $agentRunId, $artifactId, $taskSummary, $resolvedModel, $this->runStore->get($agentRunId), 'failed', $progressSeq, $progressStartedMicros);
                $this->advanceParentSequence($parentRunId, $progressSeq);

                return \sprintf("Fork timed out after %d seconds.\nArtifact: %s\nagent_run_id: %s\nUse agent_retrieve for partial handoff.", $timeoutSeconds, $artifactId, $agentRunId);
            }

            $state = $this->runStore->get($agentRunId);
            if (null === $state) {
                $this->sleepPollInterval();
                continue;
            }

            $status = $state->status;

            if (RunStatus::Running === $status || RunStatus::Queued === $status || RunStatus::Compacting === $status) {
                $entry = $this->artifactRegistry->get($parentRunId, $artifactId);
                if (null !== $entry && AgentArtifactStatusEnum::NeedsClarification === $entry->status) {
                    $this->artifactRegistry->update(
                        parentRunId: $parentRunId,
                        artifactId: $artifactId,
                        status: AgentArtifactStatusEnum::Running,
                    );
                }

                $signature = $state->lastSeq.'|'.$state->turnNo;
                if ($signature !== $lastSignature) {
                    $this->emitRunningProgress($parentRunId, $agentRunId, $artifactId, $taskSummary, $resolvedModel, $state, $progressSeq, $progressStartedMicros, 'running');
                    $this->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSignature = $signature;
                }
                $this->sleepPollInterval();
                continue;
            }

            if (RunStatus::WaitingHuman === $status) {
                $this->artifactRegistry->update(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::NeedsClarification,
                );
                $signature = $state->lastSeq.'|waiting';
                if ($signature !== $lastSignature) {
                    $this->emitRunningProgress($parentRunId, $agentRunId, $artifactId, $taskSummary, $resolvedModel, $state, $progressSeq, $progressStartedMicros, 'waiting_human');
                    $this->advanceParentSequence($parentRunId, $progressSeq);
                    ++$progressSeq;
                    $lastSignature = $signature;
                }
                $this->sleepPollInterval();
                continue;
            }

            $terminal = match ($status) {
                RunStatus::Completed => 'completed',
                RunStatus::Failed => 'failed',
                RunStatus::Cancelled, RunStatus::Cancelling => 'cancelled',
            };
            $this->emitTerminalProgress($parentRunId, $agentRunId, $artifactId, $taskSummary, $resolvedModel, $state, $terminal, $progressSeq, $progressStartedMicros);
            $this->advanceParentSequence($parentRunId, $progressSeq);

            return match ($status) {
                RunStatus::Completed => $this->handleCompleted($parentRunId, $artifactId, $agentRunId, $state),
                RunStatus::Failed => $this->handleFailed($parentRunId, $artifactId, $agentRunId, $state),
                RunStatus::Cancelled, RunStatus::Cancelling => $this->handleCancelled($parentRunId, $artifactId, $agentRunId, $state),
            };
        }
    }

    private function handleCompleted(string $parentRunId, string $artifactId, string $agentRunId, RunState $state): string
    {
        $handoff = $this->extractLastMessage($state);
        $this->finalizeArtifact(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Completed,
            summary: $handoff,
        );

        return \sprintf(
            "Fork completed.\nArtifact: %s\nagent_run_id: %s\n\n%s",
            $artifactId,
            $agentRunId,
            $handoff,
        );
    }

    private function handleFailed(string $parentRunId, string $artifactId, string $agentRunId, RunState $state): string
    {
        $error = $state->errorMessage ?? 'Run failed without error message.';
        $this->finalizeArtifact(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: $error,
            summary: $error,
        );

        return \sprintf("Fork failed: %s\nArtifact: %s\nagent_run_id: %s", $error, $artifactId, $agentRunId);
    }

    private function handleCancelled(string $parentRunId, string $artifactId, string $agentRunId, RunState $state): string
    {
        $this->finalizeArtifact(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Child run cancelled.',
        );

        return \sprintf("Fork cancelled.\nArtifact: %s\nagent_run_id: %s", $artifactId, $agentRunId);
    }

    private function finalizeArtifact(
        string $parentRunId,
        string $artifactId,
        AgentArtifactStatusEnum $status,
        ?string $summary = null,
        ?string $failureReason = null,
    ): void {
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: $status,
            completedAt: new \DateTimeImmutable(),
            summary: $summary,
            failureReason: $failureReason,
        );

        $handoff = "# Fork handoff\n\nStatus: ".$status->value."\n\n";
        if (null !== $summary && '' !== trim($summary)) {
            $handoff .= "## Result\n\n".$summary."\n";
        }
        if (null !== $failureReason && '' !== trim($failureReason)) {
            $handoff .= "\n## Failure reason\n\n".$failureReason."\n";
        }

        $this->artifactRegistry->writeHandoff($parentRunId, $artifactId, $handoff);
    }

    private function extractLastMessage(RunState $state): string
    {
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    return (string) $block['text'];
                }
            }
        }

        return 'Fork completed with status '.$state->status->value.'.';
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

    private function emitTerminalProgress(
        string $parentRunId,
        string $agentRunId,
        string $artifactId,
        string $taskSummary,
        ?string $model,
        ?RunState $state,
        string $terminalStatus,
        int $seq,
        int $progressStartedMicros,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context || null === $state) {
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
        $progress = $this->progressSnapshotBuilder->singleTerminal(
            $terminalStatus,
            self::FORK_AGENT_NAME,
            $artifactId,
            $agentRunId,
            $taskSummary,
            $state,
            $elapsedMs,
            $enrichment,
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

    private function sleepPollInterval(): void
    {
        $this->clock->sleep(self::DEFAULT_POLL_MICROS / 1_000_000);
    }
}
