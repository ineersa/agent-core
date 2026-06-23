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
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunLocator;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Psr\Log\LoggerInterface;

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
 * Only non-interactive foreground mode is supported.  Child HITL
 * (WaitingHuman) is treated as NeedsClarification — the child is
 * cancelled and a handoff explaining the unsupported state is returned.
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
        private readonly EventStoreInterface $eventStore,
        private readonly AgentChildRunLocator $childRunLocator,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Execute a single foreground subagent run.
     *
     * @param string $parentRunId        parent session run ID (required for artifact scoping)
     * @param string $agentName          agent definition name to resolve
     * @param string $task               the task text for the child agent
     * @param string $agentsMd           pre-rendered AGENTS.md project context (may be empty)
     * @param string $parentSystemPrompt parent system prompt for append mode
     *
     * @return string the final handoff/result text
     *
     * @throws ToolCallException on validation, depth, or definition errors
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
        string $agentsMd,
        string $parentSystemPrompt,
    ): string {
        // 1. Resolve and validate agent definition.
        $definition = $this->catalog->requireEnabled($agentName);

        if (!$definition->foregroundAllowed) {
            throw new ToolCallException(\sprintf('Agent "%s" does not allow foreground execution.', $agentName), retryable: false);
        }

        // 2. Check depth/recursion guard.
        $currentDepth = $this->depthGuard->currentDepth();
        $blockReason = $this->depthGuard->checkAllowed($currentDepth, $definition->maxDepth);
        if (null !== $blockReason) {
            throw new ToolCallException($blockReason, retryable: false);
        }

        // 3. Resolve tool/MCP policy.
        $policy = $this->policyResolver->resolve($definition);
        $allowedTools = $policy['tools'];

        // 4. Create artifact ID and child run ID.
        $artifactId = 'agent_'.bin2hex(random_bytes(8));
        $agentRunId = 'uuid:'.bin2hex(random_bytes(16));

        // 5. Create artifact entry in Pending.
        $entry = $this->artifactRegistry->create(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            agentName: $agentName,
        );

        // Pre-populate the locator so routers find the child store immediately.
        $this->childRunLocator->register($entry);

        // 6. Build prompt and messages.
        $prompt = $this->promptBuilder->build(
            definition: $definition,
            task: $task,
            artifactId: $artifactId,
            allowedTools: $allowedTools,
            agentsMd: $agentsMd,
            parentSystemPrompt: $parentSystemPrompt,
        );

        // 7. Build child metadata with depth, policy, and artifact paths.
        $childDepth = $this->depthGuard->childDepth($currentDepth);
        $childMetadata = new RunMetadata(
            session: [
                'kind' => 'agent_child',
                'parent_run_id' => $parentRunId,
                'agent_name' => $agentName,
                'agent_depth' => $childDepth,
                'agent_max_depth' => $definition->maxDepth,
                'agents_disabled' => $this->depthGuard->agentsGloballyDisabled(),
                'artifact_id' => $artifactId,
            ],
            model: $definition->model,
            reasoning: $definition->thinking,
            toolsScope: [
                'allowed_tools' => $allowedTools,
                'mcp' => $policy['mcp'],
            ],
        );

        // 8. Start child run.
        $childRunId = $this->agentRunner->start(new StartRunInput(
            systemPrompt: $prompt['systemPrompt'],
            messages: $prompt['messages'],
            runId: $agentRunId,
            metadata: $childMetadata,
        ));

        // 9. Mark Running in the registry.
        $startedAt = new \DateTimeImmutable();
        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: $startedAt,
        );

        // 10. Poll child until terminal, timeout, or cancellation.
        $timeoutSeconds = $this->timeoutSeconds();
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        $context = $this->contextAccessor->current();
        $cancelToken = $context?->cancellationToken();

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
                // Push inline progress update to parent transcript.
                $this->emitProgressUpdate(
                    parentRunId: $parentRunId,
                    agentName: $agentName,
                    artifactId: $artifactId,
                    state: $state,
                );

                usleep(self::DEFAULT_POLL_MICROS);
                continue;
            }

            // WaitingHuman → NeedsClarification (child HITL unsupported in v1).
            if (RunStatus::WaitingHuman === $status) {
                $this->agentRunner->cancel($agentRunId, 'Child HITL/approval is unsupported in v1.');
                $clarification = $this->extractLastMessage($state);
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::NeedsClarification,
                    summary: $clarification,
                    needsClarification: 'Child agent required human input or approval, which is unsupported in v1 foreground subagents.',
                );

                return \sprintf("Subagent %s needs clarification:\n%s\nArtifact: %s",
                    $agentName, $clarification, $artifactId);
            }

            // Terminal states — all paths return or continue.
            if (RunStatus::Completed === $status) {
                $finalMessages = $this->extractLastMessage($state);
                $this->finalize(
                    parentRunId: $parentRunId,
                    artifactId: $artifactId,
                    status: AgentArtifactStatusEnum::Completed,
                    summary: $finalMessages,
                );

                return $finalMessages;
            }

            if (RunStatus::Failed === $status) {
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

            // Cancelled, Cancelling, or any other terminal/unexpected status.
            // Cast to value for comparison to avoid PHPStan type-narrowing issues
            // with the remaining enum cases after all prior checks.
            $statusValue = $status->value;
            $isCancelled = 'cancelled' === $statusValue || 'cancelling' === $statusValue;
            $finalStatus = $isCancelled ? AgentArtifactStatusEnum::Cancelled : AgentArtifactStatusEnum::Failed;
            // @phpstan-ignore ternary.alwaysTrue (remaining statuses are all terminal but we handle unexpected values defensively)
            $summary = $isCancelled ? 'Child run was cancelled.' : 'Unexpected terminal status: '.$statusValue;

            $this->logger->info('subagent_execution.terminal', [
                'component' => 'agent.execution',
                'event_type' => 'subagent_execution.terminal',
                'agent_run_id' => $agentRunId,
                'agent_name' => $agentName,
                'status' => $status->value,
            ]);

            $this->finalize(
                parentRunId: $parentRunId,
                artifactId: $artifactId,
                status: $finalStatus,
                summary: $summary,
            );

            return \sprintf("Subagent %s ended (status: %s).\nArtifact: %s",
                $agentName, $statusValue, $artifactId);
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
     * Emit a ToolExecutionUpdate event into the parent run's event stream
     * so the RuntimeEventTranslator maps it to tool_execution.output_delta
     * in the parent's transcript.
     */
    private function emitProgressUpdate(
        string $parentRunId,
        string $agentName,
        string $artifactId,
        RunState $state,
    ): void {
        $context = $this->contextAccessor->current();
        if (null === $context) {
            return;
        }

        $delta = $this->buildProgressDelta(
            agentName: $agentName,
            artifactId: $artifactId,
            state: $state,
        );

        $event = new RunEvent(
            runId: $parentRunId,
            seq: 0,
            turnNo: 0,
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
     * Build a compact progress delta line for the inline tool widget.
     */
    private function buildProgressDelta(
        string $agentName,
        string $artifactId,
        RunState $state,
    ): string {
        $parts = [];

        $parts[] = \sprintf('subagent %s running', $agentName);
        $parts[] = \sprintf('turn %d', $state->turnNo);

        // Count tool calls executed so far from events.
        $events = $this->eventStore->allFor($state->runId);
        $toolCount = 0;
        $lastToolName = '';
        foreach ($events as $event) {
            if (RunEventTypeEnum::ToolExecutionStart->value === $event->type) {
                ++$toolCount;
                $lastToolName = (string) ($event->payload['tool_name'] ?? '');
            }
        }

        if ($toolCount > 0) {
            $parts[] = \sprintf('%d tools', $toolCount);
        }
        if ('' !== $lastToolName) {
            $parts[] = \sprintf('> %s', $lastToolName);
        }

        return implode(' | ', $parts)."\n";
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
