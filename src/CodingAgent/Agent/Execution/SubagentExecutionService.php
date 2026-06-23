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
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates a single foreground subagent execution.
 *
 * Implements the full lifecycle:
 *  1. Resolve/enforce agent definition + depth guard + tool policy.
 *  2. Create parent-scoped artifact entry.
 *  3. Build child prompt and messages.
 *  4. Start child run via AgentRunnerInterface.
 *  5. Poll child RunState until terminal, timeout, or cancellation.
 *  6. Finalize registry, handoff, and return result text.
 *
 * Only non-interactive foreground mode is supported.
 * (WaitingHuman) is unsupported — the child is cancelled and
 * the artifact is finalized as Failed (invariant failure).
 */
final class SubagentExecutionService
{
    private const int DEFAULT_POLL_MICROS = 250_000;
    private const int DEFAULT_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly AgentDefinitionCatalog $catalog,
        private readonly AgentDepthGuard $depthGuard,
        private readonly AgentToolPolicyResolver $policyResolver,
        private readonly AgentPromptBuilder $promptBuilder,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStoreInterface $runStore,
        private readonly RunStoreInterface $parentRunStore,
        private readonly EventStoreInterface $eventStore,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly AgentChildRunDirectory $childRunDirectory,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a single foreground subagent run.
     *
     * @param string $parentRunId parent session run ID (required for artifact scoping)
     * @param string $agentName   agent definition name to resolve
     * @param string $task        the task text for the child agent
     *
     * @return string the final handoff/result text
     *
     * @throws ToolCallException on validation, depth, or definition errors
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
    ): string {
        // 1. Resolve and validate agent definition.
        try {
            $definition = $this->catalog->requireEnabled($agentName);
        } catch (\RuntimeException $e) {
            throw new ToolCallException(\sprintf('Agent "%s" is not available: %s', $agentName, $e->getMessage()), retryable: false);
        }

        if (!$definition->foregroundAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow foreground execution.', $agentName), retryable: false);
        }

        // 2. Defense-in-depth: no nested subagents in v1.
        $parentIsAgentChild = $this->metadataReader->isAgentChild($parentRunId);
        $blockReason = $this->depthGuard->checkLaunchAllowed($parentIsAgentChild);
        if (null !== $blockReason) {
            throw new ToolCallException($blockReason, retryable: false);
        }

        // 3. Resolve tool/MCP policy.
        $policy = $this->policyResolver->resolve($definition);
        $allowedTools = $policy['tools'];

        // 4. Create artifact ID and child run ID (RFC 4122).
        $artifactId = 'agent_'.bin2hex(random_bytes(8));
        $agentRunId = Uuid::v4()->toRfc4122();

        // 5. Create artifact entry in Pending.
        $entry = $this->artifactRegistry->create(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: $agentName,
        );

        // Pre-populate the locator so routers find the child store immediately.
        $this->childRunDirectory->register($entry);

        // 6. Extract parent system prompt and AGENTS.md context.
        $parentSystemPrompt = $this->extractSystemPrompt($parentRunId);
        $agentsMd = $this->extractAgentsMdContext($parentRunId);

        // 7. Build prompt and messages.
        $prompt = $this->promptBuilder->build(
            definition: $definition,
            task: $task,
            artifactId: $artifactId,
            allowedTools: $allowedTools,
            agentsMd: $agentsMd,
            parentSystemPrompt: $parentSystemPrompt,
        );

        // 8. Build child metadata with policy and artifact paths.
        $childMetadata = new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'parent_run_id' => $parentRunId,
                'agent_name' => $agentName,
                'artifact_id' => $artifactId,
                'interactive' => false,
            ],
            model: $definition->model,
            reasoning: $definition->thinking,
            toolsScope: [
                'allowed_tools' => $allowedTools,
                'mcp' => $policy['mcp'],
            ],
        );

        // 9. Start child run (AgentRunner generates the runId if null).
        $this->agentRunner->start(new StartRunInput(
            systemPrompt: $prompt['systemPrompt'],
            messages: $prompt['messages'],
            runId: $agentRunId,
            metadata: $childMetadata,
        ));

        // 10. Mark Running in the registry.
        $startedAt = new \DateTimeImmutable();
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: $startedAt,
        );

        // 11. Poll child until terminal, timeout, or cancellation.
        $timeoutSeconds = $this->timeoutSeconds();
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();

        // Monotonically increasing event seq for progress updates,
        // initialised from max(parent state lastSeq, max parent event seq) + 1.
        $progressSeq = $this->resolveNextProgressSeq($parentRunId);

        while (true) {
            // Check parent cancellation.
            if (null !== $cancelToken && $cancelToken->isCancellationRequested()) {
                $this->agentRunner->cancel($agentRunId, 'Parent run cancelled subagent tool.');
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Cancelled,
                    summary: 'Cancelled by parent run.',
                );

                throw new ToolCallException('Subagent tool cancelled by parent run.', retryable: false);
            }

            // Check timeout.
            if (hrtime(true) > $deadline) {
                $this->agentRunner->cancel($agentRunId, 'Subagent timed out.');
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Failed,
                    failureReason: 'Child run timed out.',
                    summary: 'Timed out after '.$timeoutSeconds.'s.',
                );

                return \sprintf("Subagent %s timed out after %d seconds. Task: %s\nArtifact: %s",
                    $agentName, $timeoutSeconds, $task, $artifactId);
            }

            $state = $this->runStore->get($agentRunId);
            if (null === $state) {
                usleep(self::DEFAULT_POLL_MICROS);
                continue;
            }

            $status = $state->status;

            if (RunStatus::Running === $status || RunStatus::Queued === $status) {
                // Push inline progress update to parent transcript —
                // lightweight status line based on RunState, not full event scan.
                $this->emitProgressUpdate(
                    parentRunId: $parentRunId,
                    agentName: $agentName,
                    artifactId: $artifactId,
                    state: $state,
                    seq: $progressSeq,
                );

                // Advance parent RunState.lastSeq to include the progress
                // event so later ToolCallResultHandler doesn't reuse this seq.
                $this->advanceParentSequence($parentRunId, $progressSeq);
                ++$progressSeq;

                usleep(self::DEFAULT_POLL_MICROS);
                continue;
            }

            // WaitingHuman should not occur for non-interactive child runs.
            if (RunStatus::WaitingHuman === $status) {
                $this->agentRunner->cancel($agentRunId, 'Child entered unsupported WaitingHuman state.');
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Failed,
                    failureReason: 'Child agent attempted unsupported human interaction or approval.',
                    summary: 'Child run entered WaitingHuman; foreground subagents are non-interactive.',
                );

                return \sprintf('Subagent %s failed: unsupported human interaction or approval. Artifact: %s',
                    $agentName, $artifactId);
            }

            // Terminal states — exhaustive match over RunStatus.
            return match ($status) {
                RunStatus::Completed => $this->handleCompleted(
                    $parentRunId, $artifactId, $agentName, $state,
                ),
                RunStatus::Failed => $this->handleFailed(
                    $parentRunId, $artifactId, $agentName, $state,
                ),
                RunStatus::Cancelled, RunStatus::Cancelling => $this->handleCancelled(
                    $parentRunId, $artifactId, $agentName,
                ),
            };
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Finalize artifact registry entry and write handoff.
     */
    private function finalize(
        string $parentRunId,
        string $artifactId,
        AgentArtifactStatusEnum $status,
        ?string $summary = null,
        ?string $failureReason = null,
        ?string $needsClarification = null,
    ): void {
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: $status,
            completedAt: $completedAt,
            summary: $summary,
            failureReason: $failureReason,
            needsClarification: $needsClarification,
        );

        // Write handoff.md for every terminal path.
        $handoff = $this->buildHandoffMarkdown(
            status: $status,
            summary: $summary,
            failureReason: $failureReason,
            needsClarification: $needsClarification,
        );

        $this->artifactRegistry->writeHandoff($parentRunId, $artifactId, $handoff);
    }

    /**
     * Handle Completed child run — finalize and return handoff.
     */
    private function handleCompleted(
        string $parentRunId,
        string $artifactId,
        string $agentName,
        RunState $state,
    ): string {
        $finalMessages = $this->extractLastMessage($state);
        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        );

        return $finalMessages;
    }

    /**
     * Handle Failed child run — finalize and return error.
     */
    private function handleFailed(
        string $parentRunId,
        string $artifactId,
        string $agentName,
        RunState $state,
    ): string {
        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: $errorMsg,
            summary: $errorMsg,
        );

        return \sprintf("Subagent %s failed: %s\nArtifact: %s",
            $agentName, $errorMsg, $artifactId);
    }

    /**
     * Handle Cancelled/Cancelling child run — finalize and return message.
     */
    private function handleCancelled(
        string $parentRunId,
        string $artifactId,
        string $agentName,
    ): string {
        $this->logger->info('subagent_execution.cancelled', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.cancelled',
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
        ]);

        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Child run was cancelled.',
        );

        return \sprintf("Subagent %s was cancelled.\nArtifact: %s",
            $agentName, $artifactId);
    }

    /**
     * Extract the last assistant message text from RunState.
     */
    private function extractLastMessage(RunState $state): string
    {
        $lastText = '';
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            foreach ($message->content as $block) {
                if ('text' === ($block['type'] ?? '') && isset($block['text'])) {
                    $lastText = (string) $block['text'];
                    break 2;
                }
            }
        }

        if ('' === $lastText) {
            $lastText = \sprintf('%s with status %s.', $state->status->name, $state->status->value);
        }

        return $lastText;
    }

    /**
     * Build handoff markdown content for the artifact's handoff.md.
     */
    private function buildHandoffMarkdown(
        AgentArtifactStatusEnum $status,
        ?string $summary,
        ?string $failureReason,
        ?string $needsClarification,
    ): string {
        $lines = [
            '# Subagent handoff',
            '',
            'Status: '.$status->value,
        ];

        if (null !== $summary) {
            $lines[] = '';
            $lines[] = '## Result';
            $lines[] = '';
            $lines[] = $summary;
        }

        if (null !== $failureReason) {
            $lines[] = '';
            $lines[] = '## Failure reason';
            $lines[] = '';
            $lines[] = $failureReason;
        }

        if (null !== $needsClarification) {
            $lines[] = '';
            $lines[] = '## Needs clarification';
            $lines[] = '';
            $lines[] = $needsClarification;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Emit a ToolExecutionUpdate event into the parent run's event stream.
     *
     * Produces a lightweight status line (RunState-based) rather than
     * scanning the full child event log each poll.
     */
    private function emitProgressUpdate(
        string $parentRunId,
        string $agentName,
        string $artifactId,
        RunState $state,
        int $seq,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $delta = \sprintf("subagent %s running | turn %d | artifact %s\n",
            $agentName, $state->turnNo, $artifactId);

        $event = new RunEvent(
            runId: $parentRunId,
            seq: $seq,
            turnNo: $context->turnNo(),
            type: RunEventTypeEnum::ToolExecutionUpdate->value,
            payload: [
                'tool_call_id' => $context->toolCallId(),
                'tool_name' => $context->toolName(),
                'delta' => $delta,
                'order_index' => $context->orderIndex(),
            ],
        );

        $this->eventStore->append($event);
    }

    /**
     * Resolve the next sequence number for parent progress events.
     *
     * Uses the greater of parent state lastSeq and the maximum existing
     * parent event seq, so progress events never collide with events
     * appended by other writers or pending commits.
     */
    private function resolveNextProgressSeq(string $parentRunId): int
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        $stateLastSeq = null !== $parentState ? $parentState->lastSeq : 0;

        // Fallback to max event seq in case state is temporarily stale
        // (e.g. mid-commit, or another writer advanced the log without
        // yet persisting the state checkpoint).
        $parentEvents = $this->eventStore->allFor($parentRunId);
        $maxEventSeq = 0;
        foreach ($parentEvents as $event) {
            if ($event->seq > $maxEventSeq) {
                $maxEventSeq = $event->seq;
            }
        }

        return max($stateLastSeq, $maxEventSeq) + 1;
    }

    /**
     * Advance the parent RunState.lastSeq to include the progress event.
     *
     * Uses compareAndSwap in a short retry loop to handle races with
     * other parent state writers.  If CAS fails and the current state
     * already covers our seq, treats it as a non-issue.  Exhausted
     * retries are logged but do not block or re-append — the progress
     * event already exists in the event log and replay will catch up.
     */
    private function advanceParentSequence(string $parentRunId, int $seq): void
    {
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState) {
            // Parent state not yet persisted — the first progress event
            // lands while the run is still initialising.  Non-fatal.
            $this->logger->warning('subagent_execution.parent_state_missing_for_seq_advance', [
                'component' => 'agent.execution',
                'event_type' => 'subagent_execution.parent_state_missing_for_seq_advance',
                'parent_run_id' => $parentRunId,
                'target_seq' => $seq,
            ]);

            return;
        }

        $maxAttempts = 3;

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
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

            $oldVersion = $parentState->version;

            if ($this->parentRunStore->compareAndSwap($nextState, $oldVersion)) {
                return;
            }

            // CAS failed — re-read in case another writer moved past us.
            $parentState = $this->parentRunStore->get($parentRunId);
            if (null === $parentState) {
                return;
            }

            if ($parentState->lastSeq >= $seq) {
                // Another writer already advanced past our seq.
                return;
            }
        }

        // Exhausted retries; event is already in the log.
        $this->logger->warning('subagent_execution.progress_seq_cas_failed', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.progress_seq_cas_failed',
            'parent_run_id' => $parentRunId,
            'target_seq' => $seq,
            'attempts' => $maxAttempts,
            'parent_state_last_seq' => $parentState->lastSeq,
        ]);
    }

    /**
     * Extract the system prompt from the parent run's messages.
     */
    private function extractSystemPrompt(string $parentRunId): string
    {
        $state = $this->parentRunStore->get($parentRunId);
        if (null === $state) {
            return '';
        }

        foreach ($state->messages as $message) {
            if ('system' !== $message->role) {
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

    /**
     * Extract AGENTS.md context from parent run's user-context messages.
     *
     * Looks for messages with role 'user-context' and metadata source
     * 'agents_context', falling back to any user-context message when
     * the metadata tag is absent.
     */
    private function extractAgentsMdContext(string $parentRunId): string
    {
        $state = $this->parentRunStore->get($parentRunId);
        if (null === $state) {
            return '';
        }

        // Prefer explicitly tagged agents_context.
        foreach ($state->messages as $message) {
            if ('user-context' !== $message->role) {
                continue;
            }
            $source = $message->metadata['source'] ?? null;
            if ('agents_context' !== $source) {
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

    /**
     * Resolve effective timeout from ambient ToolContext or fallback default.
     */
    private function timeoutSeconds(): int
    {
        $context = $this->contextAccessor->current();
        if (null !== $context && $context->timeoutSeconds() > 0) {
            return $context->timeoutSeconds();
        }

        return self::DEFAULT_TIMEOUT_SECONDS;
    }
}
