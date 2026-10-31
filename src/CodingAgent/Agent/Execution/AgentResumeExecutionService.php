<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionNotStartedException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;
use Ineersa\CodingAgent\Tool\DTO\AgentResumeArgumentsDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Continues existing terminal child runs via follow_up + deferred batch wait.
 *
 * Owner preparation reserves the batch before ExecuteToolCall dispatch. The
 * worker consumes that projection and never reconstructs reservation eligibility.
 */
final class AgentResumeExecutionService
{
    private const int ABSOLUTE_CONTEXT_TOKEN_CEILING = 200_000;

    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly DeferredSubagentBatchRepository $batchRepository,
        private readonly DeferredSubagentChildRepository $childRepository,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly RunOperationalStatusReaderInterface $operationalStatusReader,
        private readonly EventStoreInterface $eventStore,
        private readonly RunLockManager $runLockManager,
        private readonly RunRelationshipReaderInterface $relationshipReader,
        private readonly AgentDepthGuard $depthGuard,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
        private readonly ChildInvocationLifecycleService $childLifecycle,
        private readonly ToolBatchStoreInterface $toolBatches,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Owner-side reservation before ExecuteToolCall dispatch.
     *
     * Matching prepared/launched invocations reuse the existing projection and
     * skip eligibility checks that their own earlier followUp already changed.
     */
    public function prepare(ExecuteToolCall $call): DeferredSubagentBatchProjectionDTO
    {
        if ('agent_resume' !== $call->toolName) {
            throw new ToolCallException('agent_resume preparation requires an agent_resume invocation.', retryable: false);
        }

        $parentRunId = $call->runId();
        $toolCallId = $call->toolCallId;
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing) {
            if ($existing->lifecycleId !== $lifecycleId
                || $existing->parentTurnNo !== $call->turnNo()
                || $existing->parentOrderIndex !== $call->orderIndex) {
                throw new ToolCallException('Prepared agent_resume reservation does not match its canonical batch invocation.', retryable: false);
            }
            if (DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
                throw new ToolExecutionNotStartedException('agent_resume batch launch previously failed for this tool call.');
            }

            // Already prepared or launched for this exact invocation: reuse it.
            return $existing;
        }

        try {
            $this->relationshipReader->requireKnownTopLevel($parentRunId);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false);
        }
        $depthBlock = $this->depthGuard->checkLaunchAllowed();
        if (null !== $depthBlock) {
            throw new ToolCallException($depthBlock, retryable: false);
        }

        $task = $this->decodeArguments($call->args);
        // Refuse same-batch duplicates from immutable membership before any
        // running/terminal eligibility checks that the winner may have changed.
        $entry = $this->resolveTargetIdentity($parentRunId, $task);
        $this->assertCanonicalSameChildBatchPermission(
            $parentRunId,
            $call->turnNo(),
            $call->stepId(),
            $toolCallId,
            $entry,
        );
        $entry = $this->resolveAndValidateTarget($parentRunId, $task);

        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));
        $childIntent = $this->childIntentFor($entry, $task);

        return $this->childLifecycle->reserveBatch(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: $call->turnNo(),
            parentToolCallId: $toolCallId,
            parentOrderIndex: $call->orderIndex,
            deadlineAt: $deadlineAt,
            childIntent: $childIntent,
            parentModel: $call->parentModel,
        );
    }

    public function resume(string $parentRunId, AgentResumeArgumentsDTO $task): DeferredToolCompletionOutcome
    {
        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolExecutionNotStartedException('agent_resume parent run id does not match active tool context.');
        }

        $toolCallId = $toolContext->toolCallId();
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $projection = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null === $projection) {
            throw new ToolExecutionNotStartedException('Owner-prepared agent_resume reservation is missing for this invocation.');
        }
        if ($projection->lifecycleId !== $lifecycleId
            || $projection->parentTurnNo !== $toolContext->turnNo()
            || $projection->parentOrderIndex !== $toolContext->orderIndex()) {
            throw new ToolExecutionNotStartedException('Prepared agent_resume reservation does not match its active tool context.');
        }

        $child = $this->requirePreparedChild($projection);
        if (DeferredSubagentBatchLaunchStatusEnum::Failed === $projection->launchStatus) {
            throw new ToolCallException('agent_resume batch launch previously failed for this tool call.', retryable: false);
        }
        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $projection->launchStatus) {
            // Already launched for this invocation: never followUp again.
            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $requestedTask = trim($task->task);
        if ('' === $requestedTask) {
            throw new ToolExecutionNotStartedException('agent_resume task must be non-empty.');
        }
        if ($requestedTask !== trim($child->task)) {
            throw new ToolExecutionNotStartedException('agent_resume task does not match its prepared reservation.');
        }
        if (null !== $task->artifact_id && '' !== trim($task->artifact_id) && trim($task->artifact_id) !== $child->artifactId) {
            throw new ToolExecutionNotStartedException('agent_resume artifact_id does not match its prepared reservation.');
        }
        if (null !== $task->agent_run_id && '' !== trim($task->agent_run_id) && trim($task->agent_run_id) !== $child->childRunId) {
            throw new ToolExecutionNotStartedException('agent_resume agent_run_id does not match its prepared reservation.');
        }

        $entry = $this->artifactRegistry->get($parentRunId, $child->artifactId);
        if (null === $entry || $entry->agentRunId !== $child->childRunId) {
            throw new ToolExecutionNotStartedException(\sprintf('Prepared agent_resume artifact "%s" is unavailable.', $child->artifactId));
        }
        $priorStatus = $entry->status;

        // Interruption can commit after owner preparation and before worker launch.
        // Read fresh intent before changing the artifact or dispatching follow-up.
        if (null !== $this->batchRepository->findByLifecycleId($lifecycleId)?->interruptionKind) {
            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        try {
            try {
                $this->artifactRegistry->update(
                    parentRunId: $parentRunId,
                    artifactId: $child->artifactId,
                    status: AgentArtifactStatusEnum::Running,
                    startedAt: Clock::get()->now(),
                );
            } catch (\Throwable $markRunningFailure) {
                $this->logger->warning('agent_resume.artifact_running_persist_failed', [
                    'run_id' => $parentRunId,
                    'tool_call_id' => $toolCallId,
                    'child_run_id' => $child->childRunId,
                    'artifact_id' => $child->artifactId,
                    'component' => 'agent.execution',
                    'event_type' => 'agent_resume.artifact_running_persist_failed',
                    'exception_class' => $markRunningFailure::class,
                ]);
            }
            try {
                $this->agentRunner->followUp(
                    $child->childRunId,
                    new AgentMessage(
                        role: 'user',
                        content: [['type' => 'text', 'text' => $requestedTask]],
                        metadata: ['agent_task' => ['artifact_id' => $child->artifactId]],
                    ),
                );
            } catch (\Throwable $followUpFailure) {
                try {
                    $this->artifactRegistry->update(
                        parentRunId: $parentRunId,
                        artifactId: $child->artifactId,
                        status: $priorStatus,
                    );
                } catch (\Throwable $revertFailure) {
                    $this->logger->warning('agent_resume.artifact_running_revert_failed', [
                        'run_id' => $parentRunId,
                        'tool_call_id' => $toolCallId,
                        'child_run_id' => $child->childRunId,
                        'artifact_id' => $child->artifactId,
                        'prior_status' => $priorStatus->value,
                        'component' => 'agent.execution',
                        'event_type' => 'agent_resume.artifact_running_revert_failed',
                        'exception_class' => $revertFailure::class,
                    ]);
                }

                throw $followUpFailure;
            }
        } catch (\Throwable $e) {
            // Failed followUp restores the child's prior terminal artifact status (best-effort).
            if ($e instanceof ToolCallException) {
                throw $e;
            }

            throw new ToolCallException('agent_resume failed to follow_up the child.', retryable: false, previous: $e);
        }

        try {
            $this->childLifecycle->publishLaunchSuccessState(
                $parentRunId,
                $toolCallId,
                $lifecycleId,
                Clock::get()->now(),
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
     * Same-child duplicates are refused from the registered tool batch order.
     * The earliest call targeting a resolved child may resume; later aliases of
     * that child stay refused even after the winner finishes.
     */
    private function assertCanonicalSameChildBatchPermission(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        AgentArtifactEntryDTO $entry,
    ): void {
        if ('' === trim($stepId)) {
            throw new ToolCallException('agent_resume requires an active tool batch step.', retryable: false);
        }

        $schedule = $this->toolBatches->loadSchedule($runId, $turnNo, $stepId);
        if (null === $schedule) {
            throw new ToolCallException('agent_resume requires a registered tool batch.', retryable: false);
        }
        if (!\array_key_exists($toolCallId, $schedule->expectedOrder)) {
            throw new ToolCallException('agent_resume is not a member of the registered tool batch.', retryable: false);
        }

        $winnerToolCallId = null;
        $winnerOrder = null;
        foreach ($schedule->expectedOrder as $candidateToolCallId => $orderIndex) {
            $call = $this->toolBatches->loadCall($runId, $turnNo, $stepId, $candidateToolCallId);
            if (null === $call || 'agent_resume' !== $call->toolName) {
                continue;
            }
            try {
                $arguments = $this->decodeArguments($call->args);
            } catch (ToolCallException $error) {
                // Malformed sibling arguments cannot win; keep scanning the batch.
                $this->logger->debug('agent_resume.batch_sibling_args_unreadable', [
                    'run_id' => $runId,
                    'tool_call_id' => $candidateToolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'agent_resume.batch_sibling_args_unreadable',
                    'exception_class' => $error::class,
                ]);
                continue;
            }
            try {
                $candidate = $this->resolveTargetIdentity($runId, $arguments);
            } catch (ToolCallException $error) {
                // Unresolvable siblings stay out of the canonical winner set.
                $this->logger->debug('agent_resume.batch_sibling_identity_unresolved', [
                    'run_id' => $runId,
                    'tool_call_id' => $candidateToolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'agent_resume.batch_sibling_identity_unresolved',
                    'exception_class' => $error::class,
                ]);
                continue;
            }
            if ($candidate->artifactId !== $entry->artifactId || $candidate->agentRunId !== $entry->agentRunId) {
                continue;
            }
            if (null === $winnerOrder || $orderIndex < $winnerOrder || ($orderIndex === $winnerOrder && $candidateToolCallId < (string) $winnerToolCallId)) {
                $winnerOrder = $orderIndex;
                $winnerToolCallId = $candidateToolCallId;
            }
        }

        if (null === $winnerToolCallId) {
            throw new ToolCallException('agent_resume could not resolve a canonical batch winner for this child.', retryable: false);
        }
        if ($winnerToolCallId !== $toolCallId) {
            throw new ToolCallException(\sprintf('Duplicate agent_resume for child "%s" in this tool batch. Only the first call may continue it.', $entry->artifactId), retryable: false);
        }
    }

    /** @param array<string, mixed> $args */
    private function decodeArguments(array $args): AgentResumeArgumentsDTO
    {
        try {
            $arguments = $this->denormalizer->denormalize($args, AgentResumeArgumentsDTO::class);
        } catch (SerializerExceptionInterface $error) {
            throw new ToolCallException('agent_resume arguments are invalid.', retryable: false, previous: $error);
        }
        if (!$arguments instanceof AgentResumeArgumentsDTO) {
            throw new ToolCallException('agent_resume arguments are invalid.', retryable: false);
        }
        $errors = $this->validator->validate($arguments);
        if (0 !== \count($errors)) {
            throw new ToolCallException((string) $errors->get(0)->getMessage(), retryable: false, previous: new ValidationFailedException($arguments, $errors));
        }

        return $arguments;
    }

    private function childIntentFor(AgentArtifactEntryDTO $entry, AgentResumeArgumentsDTO $task): DeferredSubagentBatchChildIntentDTO
    {
        $childRow = $this->childRepository->findByChildRunId($entry->agentRunId);
        $launchModel = $childRow?->launchModel;
        $launchReasoning = $childRow?->launchReasoning;
        if (null === $launchModel || '' === trim($launchModel) || null === $launchReasoning || '' === trim($launchReasoning)) {
            $projectionModel = $childRow?->childLifecycleProjection?->model;
            $projectionReasoning = $childRow?->childLifecycleProjection?->reasoning;
            $launchModel = (null !== $projectionModel && '' !== trim($projectionModel)) ? $projectionModel : 'unknown/model';
            $launchReasoning = (null !== $projectionReasoning && '' !== trim($projectionReasoning)) ? $projectionReasoning : 'medium';
        }

        return new DeferredSubagentBatchChildIntentDTO(
            batchIndex: 1,
            childRunId: $entry->agentRunId,
            artifactId: $entry->artifactId,
            agentName: $entry->agentName,
            task: trim($task->task),
            launchModel: $launchModel,
            launchReasoning: $launchReasoning,
        );
    }

    private function requirePreparedChild(DeferredSubagentBatchProjectionDTO $projection): DeferredSubagentChildProjectionDTO
    {
        if (1 !== \count($projection->children)) {
            throw new ToolCallException('Prepared agent_resume reservation must contain exactly one child.', retryable: false);
        }

        return $projection->children[0];
    }

    private function resolveTargetIdentity(string $parentRunId, AgentResumeArgumentsDTO $task): AgentArtifactEntryDTO
    {
        $artifactId = null === $task->artifact_id ? null : trim($task->artifact_id);
        $agentRunId = null === $task->agent_run_id ? null : trim($task->agent_run_id);

        $byArtifact = null;
        $byRun = null;
        if (null !== $artifactId && '' !== $artifactId) {
            try {
                $byArtifact = $this->artifactRegistry->get($parentRunId, $artifactId);
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage(), retryable: false);
            }
            if (null === $byArtifact) {
                throw new ToolCallException(\sprintf('Unknown artifact_id "%s" in the current parent session.', $artifactId), retryable: false);
            }
        }
        if (null !== $agentRunId && '' !== $agentRunId) {
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

        return $entry;
    }

    private function resolveAndValidateTarget(string $parentRunId, AgentResumeArgumentsDTO $task): AgentArtifactEntryDTO
    {
        if ('' === trim($task->task)) {
            throw new ToolCallException('agent_resume task must be non-empty.', retryable: false);
        }

        $entry = $this->resolveTargetIdentity($parentRunId, $task);

        if (!$this->artifactRegistry->belongsToCurrentParentLifetime($parentRunId, $entry->artifactId)) {
            throw new ToolCallException(\sprintf('Artifact "%s" belongs to a previous parent lifetime and cannot be resumed after parent /resume.', $entry->artifactId), retryable: false);
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
            $state = $this->runLockManager->synchronized($entry->agentRunId, function () use ($entry): RunOperationalStatusDTO {
                $status = $this->operationalStatusReader->findOperationalStatus($entry->agentRunId);
                if (null === $status) {
                    throw new \RuntimeException('Operational child status is unavailable; recovery required.');
                }
                // Resume is an explicit lifecycle boundary, not a status poll.
                // A failed post-append projection write can leave a terminal row
                // behind newer canonical work. Read only the actual archive tail,
                // under owner serialization, never the sequence allocation cursor.
                if ($status->status->isTerminal()) {
                    $latest = $this->eventStore->latestSequenceFor($entry->agentRunId);
                    if (null === $latest || $latest !== $status->lastEventSequence) {
                        throw new \RuntimeException('Operational child status is not current; recovery required.');
                    }
                }

                return $status;
            });
        } catch (\Throwable $e) {
            throw new ToolCallException(\sprintf('Child run "%s" is unusable for resume.', $entry->agentRunId), retryable: false, previous: $e);
        }

        if (\in_array($state->status, [RunStatus::Cancelling], true)) {
            throw new ToolCallException(\sprintf('Child run "%s" is mid-cancel and cannot be resumed yet.', $entry->agentRunId), retryable: false);
        }

        if (!$state->status->isTerminal()) {
            throw new ToolCallException(\sprintf('Child run "%s" is not terminal (status=%s). Wait for completion before resuming.', $entry->agentRunId, $state->status->value), retryable: false);
        }

        $this->assertContextBudgetAllowsResume($entry);

        return $entry;
    }

    private function assertContextBudgetAllowsResume(AgentArtifactEntryDTO $entry): void
    {
        $child = $this->childRepository->findByChildRunId($entry->agentRunId);
        $projection = $child?->childLifecycleProjection;
        $latestInputTokens = null === $projection ? 0 : $projection->latestInputTokens;
        $contextWindow = null === $projection ? null : $projection->contextWindow;
        $threshold = null !== $contextWindow && $contextWindow > 0
            ? min((int) floor(0.75 * $contextWindow), self::ABSOLUTE_CONTEXT_TOKEN_CEILING)
            : self::ABSOLUTE_CONTEXT_TOKEN_CEILING;

        if ($latestInputTokens >= $threshold) {
            throw new ToolCallException(\sprintf('Refusing to resume artifact "%s": child context is near the limit (%d latest input tokens; threshold %d). Launch a fresh subagent instead.', $entry->artifactId, $latestInputTokens, $threshold), retryable: false);
        }
    }
}
