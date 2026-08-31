<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchPlanDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Continues existing terminal child runs via follow_up + deferred batch wait.
 */
final class AgentResumeExecutionService
{
    private const int ABSOLUTE_CONTEXT_TOKEN_FLOOR = 200_000;

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly DeferredSubagentBatchRepository $batchRepository,
        private readonly DeferredSubagentChildRepository $childRepository,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunStateRebuilderInterface $runStateRebuilder,
        private readonly RunStartedMetadataReader $metadataReader,
        private readonly RunRelationshipReaderInterface $relationshipReader,
        private readonly AgentDepthGuard $depthGuard,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<AgentResumeTaskDTO> $tasks
     */
    public function resume(string $parentRunId, array $tasks, ChildRunBatchExecutionModeEnum $executionMode): DeferredToolCompletionOutcome
    {
        try {
            $this->relationshipReader->requireKnownTopLevel($parentRunId);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false);
        }
        $depthBlock = $this->depthGuard->checkLaunchAllowed();
        if (null !== $depthBlock) {
            throw new ToolCallException($depthBlock, retryable: false);
        }

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('agent_resume parent run id does not match active tool context.', retryable: false);
        }

        $resolved = [];
        $seenArtifactIds = [];
        /** @var array<string, RunState> $replayedChildStates */
        $replayedChildStates = [];
        foreach ($tasks as $index => $task) {
            $entry = $this->resolveAndValidateTarget($parentRunId, $task, $replayedChildStates);
            // DTO uniqueness cannot cover agent_run_id→artifact aliases; dedupe after registry resolve.
            if (isset($seenArtifactIds[$entry->artifactId])) {
                throw new ToolCallException(\sprintf('Duplicate artifact_id "%s" in one agent_resume call.', $entry->artifactId), retryable: false);
            }
            $seenArtifactIds[$entry->artifactId] = true;
            $resolved[] = [
                'batchIndex' => $index + 1,
                'entry' => $entry,
                'task' => (string) $task->task,
            ];
        }

        $toolCallId = $toolContext->toolCallId();
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        $childIntents = [];
        $identities = [];
        foreach ($resolved as $item) {
            /** @var AgentArtifactEntryDTO $entry */
            $entry = $item['entry'];
            $childRow = $this->childRepository->findByChildRunId($entry->agentRunId);
            $launchModel = $childRow?->launchModel;
            $launchReasoning = $childRow?->launchReasoning;
            if (null === $launchModel || '' === trim($launchModel) || null === $launchReasoning || '' === trim($launchReasoning)) {
                $projectionModel = $childRow?->childLifecycleProjection?->model;
                $projectionReasoning = $childRow?->childLifecycleProjection?->reasoning;
                $launchModel = (null !== $projectionModel && '' !== trim($projectionModel)) ? $projectionModel : 'unknown/model';
                $launchReasoning = (null !== $projectionReasoning && '' !== trim($projectionReasoning)) ? $projectionReasoning : 'medium';
            }

            $childIntents[] = new DeferredSubagentBatchChildIntentDTO(
                batchIndex: $item['batchIndex'],
                childRunId: $entry->agentRunId,
                artifactId: $entry->artifactId,
                agentName: $entry->agentName,
                task: $item['task'],
                launchModel: $launchModel,
                launchReasoning: $launchReasoning,
            );
            $identities[] = new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $entry->agentRunId,
                artifactId: $entry->artifactId,
                displayName: $entry->agentName,
                taskSummary: $item['task'],
                launchModel: $launchModel,
                launchReasoning: $launchReasoning,
                artifactKind: $entry->kind,
                batchIndex: $item['batchIndex'],
            );
        }

        $plan = new DeferredSubagentBatchLaunchPlanDTO(
            lifecycleId: $lifecycleId,
            executionMode: $executionMode,
            totalChildCount: \count($resolved),
            childIntents: $childIntents,
            definitionsByBatchIndex: [],
            identities: $identities,
            parentModel: $toolContext->parentModel(),
        );

        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('agent_resume batch launch previously failed for this tool call.', retryable: false);
        }
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Launched === $existing->launchStatus) {
            $this->batchRepository->reserveBatch(
                lifecycleId: $lifecycleId,
                parentRunId: $parentRunId,
                parentTurnNo: $toolContext->turnNo(),
                parentToolCallId: $toolCallId,
                parentOrderIndex: $toolContext->orderIndex(),
                executionMode: $executionMode,
                totalChildCount: \count($resolved),
                deadlineAt: $deadlineAt,
                childIntents: $plan->reserveChildIntents(),
                parentModel: $plan->parentModel,
            );

            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $this->batchRepository->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: $toolContext->turnNo(),
            parentToolCallId: $toolCallId,
            parentOrderIndex: $toolContext->orderIndex(),
            executionMode: $executionMode,
            totalChildCount: \count($resolved),
            deadlineAt: $deadlineAt,
            childIntents: $plan->reserveChildIntents(),
            parentModel: $plan->parentModel,
        );

        /** @var list<int> $launchedBeforeFailure */
        $launchedBeforeFailure = [];
        try {
            foreach ($resolved as $item) {
                /** @var AgentArtifactEntryDTO $entry */
                $entry = $item['entry'];
                try {
                    $this->artifactRegistry->update(
                        parentRunId: $parentRunId,
                        artifactId: $entry->artifactId,
                        status: AgentArtifactStatusEnum::Running,
                        startedAt: Clock::get()->now(),
                    );
                } catch (\Throwable $markRunningFailure) {
                    $this->logger->warning('agent_resume.artifact_running_persist_failed', [
                        'run_id' => $parentRunId,
                        'tool_call_id' => $toolCallId,
                        'child_run_id' => $entry->agentRunId,
                        'artifact_id' => $entry->artifactId,
                        'component' => 'agent.execution',
                        'event_type' => 'agent_resume.artifact_running_persist_failed',
                        'exception_class' => $markRunningFailure::class,
                    ]);
                }
                try {
                    $this->agentRunner->followUp(
                        $entry->agentRunId,
                        new AgentMessage(
                            role: 'user',
                            content: [['type' => 'text', 'text' => $item['task']]],
                        ),
                    );
                } catch (\Throwable $followUpFailure) {
                    try {
                        $this->artifactRegistry->update(
                            parentRunId: $parentRunId,
                            artifactId: $entry->artifactId,
                            status: $entry->status,
                        );
                    } catch (\Throwable $revertFailure) {
                        $this->logger->warning('agent_resume.artifact_running_revert_failed', [
                            'run_id' => $parentRunId,
                            'tool_call_id' => $toolCallId,
                            'child_run_id' => $entry->agentRunId,
                            'artifact_id' => $entry->artifactId,
                            'prior_status' => $entry->status->value,
                            'component' => 'agent.execution',
                            'event_type' => 'agent_resume.artifact_running_revert_failed',
                            'exception_class' => $revertFailure::class,
                        ]);
                    }

                    throw $followUpFailure;
                }
                $launchedBeforeFailure[] = $item['batchIndex'];
            }
        } catch (\Throwable $e) {
            $failureBatchIndex = null;
            foreach ($resolved as $candidate) {
                if (!\in_array($candidate['batchIndex'], $launchedBeforeFailure, true)) {
                    $failureBatchIndex = $candidate['batchIndex'];
                    break;
                }
            }
            $failureBatchIndex ??= ([] === $launchedBeforeFailure ? 1 : (max($launchedBeforeFailure) + 1));

            try {
                $this->batchRepository->applyLaunchFailureRuntime(
                    $parentRunId,
                    $toolCallId,
                    $lifecycleId,
                    $failureBatchIndex,
                    $launchedBeforeFailure,
                );
            } catch (\Throwable $persistFailure) {
                $this->logger->warning('agent_resume.launch_failure_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'agent_resume.launch_failure_persist_failed',
                    'failure_phase' => 'follow_up',
                    'failure_batch_index' => $failureBatchIndex,
                    'exception_class' => $persistFailure::class,
                ]);
            }

            // Failed followUp reverts that child's artifact to its prior terminal status
            // (best-effort). Successfully followUp'd siblings remain Running.
            if ($e instanceof ToolCallException) {
                throw $e;
            }

            throw new ToolCallException('agent_resume failed to follow_up one or more children.', retryable: false, previous: $e);
        }

        $launchedIndices = array_map(static fn (array $item): int => $item['batchIndex'], $resolved);
        try {
            $this->batchRepository->applyLaunchSuccessState(
                $parentRunId,
                $toolCallId,
                $lifecycleId,
                Clock::get()->now(),
                $launchedIndices,
            );
        } catch (\Throwable $persistFailure) {
            // follow_up already dispatched; leave the Reserved batch recoverable for the
            // deferred recovery path instead of incorrectly failing the parent tool call.
            $this->logger->warning('agent_resume.launch_success_persist_failed', [
                'run_id' => $parentRunId,
                'session_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'lifecycle_id' => $lifecycleId,
                'component' => 'agent.execution',
                'event_type' => 'agent_resume.launch_success_persist_failed',
                'exception_class' => $persistFailure::class,
            ]);
        }

        return new DeferredToolCompletionOutcome($lifecycleId);
    }

    /**
     * @param array<string, RunState> $replayedChildStates
     */
    private function resolveAndValidateTarget(string $parentRunId, AgentResumeTaskDTO $task, array &$replayedChildStates): AgentArtifactEntryDTO
    {
        $artifactId = $task->artifact_id;
        $agentRunId = $task->agent_run_id;
        if (null === $task->task) {
            throw new ToolCallException('agent_resume task must be non-empty.', retryable: false);
        }

        $byArtifact = null;
        $byRun = null;
        if (null !== $artifactId) {
            try {
                $byArtifact = $this->artifactRegistry->get($parentRunId, $artifactId);
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage(), retryable: false);
            }
            if (null === $byArtifact) {
                throw new ToolCallException(\sprintf('Unknown artifact_id "%s" in the current parent session.', $artifactId), retryable: false);
            }
        }
        if (null !== $agentRunId) {
            try {
                $byRun = $this->artifactRegistry->findByAgentRunId($parentRunId, $agentRunId);
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage(), retryable: false);
            }
            if (null === $byRun) {
                throw new ToolCallException(\sprintf('Unknown agent_run_id "%s" for the current parent session.', $agentRunId), retryable: false);
            }
        }

        if (null !== $byArtifact && null !== $byRun && ($byArtifact->artifactId !== $byRun->artifactId || $byArtifact->agentRunId !== $byRun->agentRunId)) {
            throw new ToolCallException('artifact_id and agent_run_id refer to different subagent artifacts.', retryable: false);
        }

        $entry = $byArtifact ?? $byRun;
        if (null === $entry) {
            throw new ToolCallException('Unable to resolve artifact for agent_resume.', retryable: false);
        }

        if (!$this->artifactRegistry->belongsToCurrentParentLifetime($parentRunId, $entry->artifactId)) {
            throw new ToolCallException(\sprintf('Artifact "%s" belongs to a previous parent lifetime and cannot be resumed after parent /resume.', $entry->artifactId), retryable: false);
        }

        if (AgentArtifactKindEnum::Fork === $entry->kind) {
            throw new ToolCallException('agent_resume cannot resume fork children.', retryable: false);
        }

        $childMeta = $this->metadataReader->readRunStartedMetadata($entry->agentRunId);
        if (null !== $childMeta && 'fork' === ($childMeta->session->childKind ?? null)) {
            throw new ToolCallException('agent_resume cannot resume fork children.', retryable: false);
        }

        if (\in_array($entry->status, [AgentArtifactStatusEnum::Running, AgentArtifactStatusEnum::NeedsClarification], true)) {
            throw new ToolCallException(\sprintf('Artifact "%s" is already in flight (status=%s).', $entry->artifactId, $entry->status->value), retryable: false);
        }

        if (!\in_array($entry->status, [
            AgentArtifactStatusEnum::Completed,
            AgentArtifactStatusEnum::Failed,
            AgentArtifactStatusEnum::Cancelled,
        ], true)) {
            throw new ToolCallException(\sprintf('Artifact "%s" is not resumable from status "%s".', $entry->artifactId, $entry->status->value), retryable: false);
        }

        try {
            $state = $replayedChildStates[$entry->agentRunId] ?? null;
            if (null === $state) {
                $state = $this->rebuildChildState($entry->agentRunId);
                $replayedChildStates[$entry->agentRunId] = $state;
            }
        } catch (\Throwable $e) {
            throw new ToolCallException(\sprintf('Child run "%s" is unusable for resume.', $entry->agentRunId), retryable: false, previous: $e);
        }

        if (\in_array($state->status, [RunStatus::Cancelling], true)) {
            throw new ToolCallException(\sprintf('Child run "%s" is mid-cancel and cannot be resumed yet.', $entry->agentRunId), retryable: false);
        }

        $this->assertContextBudgetAllowsResume($entry);

        return $entry;
    }

    /**
     * Resume is an explicit cross-process lifecycle boundary. It must rebuild
     * canonical child events once rather than trusting the legacy state snapshot.
     */
    private function rebuildChildState(string $childRunId): RunState
    {
        $state = $this->runStateRebuilder
            ->rebuildIfStale(RunState::queued($childRunId), $childRunId)
            ->rebuiltState;
        if (null === $state) {
            throw new \RuntimeException('Canonical child run state is unavailable.');
        }

        return $state;
    }

    private function assertContextBudgetAllowsResume(AgentArtifactEntryDTO $entry): void
    {
        $child = $this->childRepository->findByChildRunId($entry->agentRunId);
        $projection = $child?->childLifecycleProjection;
        $latestInputTokens = null === $projection ? 0 : $projection->latestInputTokens;
        $contextWindow = null === $projection ? null : $projection->contextWindow;
        $threshold = null !== $contextWindow && $contextWindow > 0
            ? max((int) floor(0.75 * $contextWindow), self::ABSOLUTE_CONTEXT_TOKEN_FLOOR)
            : self::ABSOLUTE_CONTEXT_TOKEN_FLOOR;

        if ($latestInputTokens >= $threshold) {
            throw new ToolCallException(\sprintf('Refusing to resume artifact "%s": child context is near the limit (%d latest input tokens; threshold %d). Launch a fresh subagent instead.', $entry->artifactId, $latestInputTokens, $threshold), retryable: false);
        }
    }
}
