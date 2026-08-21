<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
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
        private readonly RunStoreInterface $runStore,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly AgentDepthGuard $depthGuard,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    /**
     * @param list<AgentResumeTaskDTO> $tasks
     */
    public function resume(string $parentRunId, array $tasks, ChildRunBatchExecutionModeEnum $executionMode): DeferredToolCompletionOutcome
    {
        if ([] === $tasks) {
            throw new ToolCallException('agent_resume requires at least one task.', retryable: false);
        }

        if (ChildRunBatchExecutionModeEnum::Single === $executionMode && 1 !== \count($tasks)) {
            throw new ToolCallException('Single agent_resume requires exactly one task.', retryable: false);
        }

        $maxAgents = $this->agentsConfig->maxAgents;
        if (\count($tasks) > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel agent_resume supports at most %d children per tool call, but %d tasks were requested.', $maxAgents, \count($tasks)), retryable: false);
        }

        $depthBlock = $this->depthGuard->checkLaunchAllowed($this->metadataReader->isAgentChild($parentRunId));
        if (null !== $depthBlock) {
            throw new ToolCallException($depthBlock, retryable: false);
        }

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('agent_resume parent run id does not match active tool context.', retryable: false);
        }

        $resolved = [];
        $seenArtifactIds = [];
        foreach ($tasks as $index => $task) {
            $entry = $this->resolveAndValidateTarget($parentRunId, $task);
            if (isset($seenArtifactIds[$entry->artifactId])) {
                throw new ToolCallException(\sprintf('Duplicate artifact_id "%s" in one agent_resume call.', $entry->artifactId), retryable: false);
            }
            $seenArtifactIds[$entry->artifactId] = true;
            $resolved[] = [
                'batchIndex' => $index + 1,
                'entry' => $entry,
                'task' => $task->trimmedTask(),
            ];
        }

        $toolCallId = $toolContext->toolCallId();
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('agent_resume batch launch previously failed for this tool call.', retryable: false);
        }
        if (null !== $existing && DeferredSubagentBatchLaunchStatusEnum::Launched === $existing->launchStatus) {
            return new DeferredToolCompletionOutcome($lifecycleId);
        }

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

        try {
            foreach ($resolved as $item) {
                /** @var AgentArtifactEntryDTO $entry */
                $entry = $item['entry'];
                $this->artifactRegistry->update(
                    parentRunId: $parentRunId,
                    artifactId: $entry->artifactId,
                    status: AgentArtifactStatusEnum::Running,
                    startedAt: Clock::get()->now(),
                );

                $this->agentRunner->followUp(
                    $entry->agentRunId,
                    new AgentMessage(
                        role: 'user',
                        content: [['type' => 'text', 'text' => $item['task']]],
                    ),
                );
            }
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('agent_resume failed to follow_up one or more children.', retryable: false, previous: $e);
        }

        $launchedIndices = array_map(static fn (array $item): int => $item['batchIndex'], $resolved);
        $this->batchRepository->applyLaunchSuccessState(
            $parentRunId,
            $toolCallId,
            $lifecycleId,
            Clock::get()->now(),
            $launchedIndices,
        );

        return new DeferredToolCompletionOutcome($lifecycleId);
    }

    private function resolveAndValidateTarget(string $parentRunId, AgentResumeTaskDTO $task): AgentArtifactEntryDTO
    {
        $artifactId = $task->trimmedArtifactId();
        $agentRunId = $task->trimmedAgentRunId();
        if ('' === $task->trimmedTask()) {
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
            $state = $this->runStore->get($entry->agentRunId);
        } catch (\Throwable $e) {
            throw new ToolCallException(\sprintf('Child run "%s" is unusable for resume.', $entry->agentRunId), retryable: false, previous: $e);
        }

        if (\in_array($state->status, [RunStatus::Cancelling], true)) {
            throw new ToolCallException(\sprintf('Child run "%s" is mid-cancel and cannot be resumed yet.', $entry->agentRunId), retryable: false);
        }

        $this->assertContextBudgetAllowsResume($entry);

        return $entry;
    }

    private function assertContextBudgetAllowsResume(AgentArtifactEntryDTO $entry): void
    {
        $child = $this->childRepository->findByChildRunId($entry->agentRunId);
        $projection = $child?->childLifecycleProjection;
        $latestInputTokens = $projection?->latestInputTokens ?? 0;
        $contextWindow = $projection?->contextWindow;
        $threshold = null !== $contextWindow && $contextWindow > 0
            ? max((int) floor(0.75 * $contextWindow), self::ABSOLUTE_CONTEXT_TOKEN_FLOOR)
            : self::ABSOLUTE_CONTEXT_TOKEN_FLOOR;

        if ($latestInputTokens >= $threshold) {
            throw new ToolCallException(\sprintf('Refusing to resume artifact "%s": child context is near the limit (%d latest input tokens; threshold %d). Launch a fresh subagent instead.', $entry->artifactId, $latestInputTokens, $threshold), retryable: false);
        }
    }
}
