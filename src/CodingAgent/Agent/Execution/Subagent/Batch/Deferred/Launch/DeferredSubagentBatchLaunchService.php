<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\DiagnosticMessageSanitizer;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

/**
 * Durable idempotent one-child launch using deferred batch supervision.
 *
 * The tool reserves its deterministic child identity after argument validation.
 * Replayed intents reuse the same batch and native child run.
 */
final class DeferredSubagentBatchLaunchService
{
    public function __construct(
        private readonly DeferredSubagentBatchPreparationService $batchPreparation,
        private readonly DeferredSubagentBatchRepository $batchRepository,
        private readonly DeferredSubagentBatchRuntimeStartService $runtimeStart,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function launch(
        string $parentRunId,
        string $agentName,
        string $task,
    ): DeferredToolCompletionOutcome {
        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $plan = $this->batchPreparation->buildLaunchPlan(
            $parentRunId,
            $toolCallId,
            trim($agentName),
            trim($task),
            parentModel: $toolContext->parentModel(),
            launchContext: $toolContext->launchContext(),
        );
        $projection = $this->reserveInvocation($parentRunId, $toolCallId, $plan);
        $this->assertPlanMatchesPreparedChild($plan, $this->requirePreparedChild($projection));

        return $this->launchWithPlan(
            parentRunId: $parentRunId,
            toolCallId: $toolCallId,
            plan: $plan,
            projection: $projection,
            prepare: fn (DeferredSubagentBatchProjectionDTO $reserved): ?PreparedAgentChildRunDTO => $this->batchPreparation->preparePendingChild(
                $parentRunId,
                $reserved,
                $plan,
            ),
        );
    }

    /**
     * Explicit required single-child profiled launch used by fork.
     *
     * Parallelism across multiple fork tool calls is handled by ToolBatchCollector;
     * each call still launches exactly one deferred child through this path.
     */
    public function launchSingleChildProfile(
        string $parentRunId,
        string $task,
        DeferredSubagentSingleChildLaunchProfileDTO $profile,
    ): DeferredToolCompletionOutcome {
        $task = trim($task);
        if ('' === $task) {
            throw new ToolCallException('Profiled single-child launch requires a non-empty task.', retryable: false);
        }

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $plan = $this->batchPreparation->buildSingleChildProfiledLaunchPlan(
            $parentRunId,
            $toolCallId,
            $task,
            $profile,
            parentModel: $toolContext->parentModel(),
            launchContext: $toolContext->launchContext(),
        );
        $projection = $this->reserveInvocation($parentRunId, $toolCallId, $plan);
        $this->assertPlanMatchesPreparedChild($plan, $this->requirePreparedChild($projection));

        return $this->launchWithPlan(
            parentRunId: $parentRunId,
            toolCallId: $toolCallId,
            plan: $plan,
            projection: $projection,
            prepare: fn (DeferredSubagentBatchProjectionDTO $reserved): ?PreparedAgentChildRunDTO => $this->batchPreparation->preparePendingProfiledChild(
                $parentRunId,
                $reserved,
                $plan,
                $profile,
            ),
        );
    }

    public function findInvocation(string $parentRunId, string $toolCallId): ?DeferredSubagentBatchProjectionDTO
    {
        return $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
    }

    private function reserveInvocation(string $parentRunId, string $toolCallId, DeferredSubagentBatchLaunchPlanDTO $plan): DeferredSubagentBatchProjectionDTO
    {
        $context = $this->contextAccessor->requireCurrent();
        $projection = $this->batchRepository->reserveBatch(
            $plan->lifecycleId,
            $parentRunId,
            $context->turnNo(),
            $toolCallId,
            $context->orderIndex(),
            Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds)),
            $plan->childIntent,
            $context->parentModel(),
        );
        if ($projection->parentTurnNo !== $context->turnNo() || $projection->parentOrderIndex !== $context->orderIndex()) {
            throw new ToolCallException('Child invocation does not match its tool context.', retryable: false);
        }

        return $projection;
    }

    /**
     * @param callable(DeferredSubagentBatchProjectionDTO): ?PreparedAgentChildRunDTO $prepare
     */
    private function launchWithPlan(
        string $parentRunId,
        string $toolCallId,
        DeferredSubagentBatchLaunchPlanDTO $plan,
        DeferredSubagentBatchProjectionDTO $projection,
        callable $prepare,
    ): DeferredToolCompletionOutcome {
        $lifecycleId = $plan->lifecycleId;
        if (DeferredSubagentBatchLaunchStatusEnum::Failed === $projection->launchStatus) {
            throw new ToolCallException('Subagent batch launch previously failed for this tool call.', retryable: false);
        }
        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $projection->launchStatus) {
            // Already launched for this invocation: never prepare/start again.
            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        // Parent cancel/timeout can land while children are still Reserved.
        // Do not prepare/start after interruption intent is durable; return the
        // deferred outcome so registration can complete interruption delivery.
        if ($this->hasInterruptionIntent($lifecycleId)) {
            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        try {
            $prepared = $prepare($projection);
        } catch (DeferredSubagentBatchPreparationFailure $e) {
            $this->runtimeStart->abortAfterPreparationFailure(
                $parentRunId,
                $toolCallId,
                $plan->identity,
                $e->getPrevious() ?? $e,
                $e->failureBatchIndex,
            );
            $this->batchRepository->applyLaunchFailurePreparation($parentRunId, $toolCallId, $lifecycleId);

            throw $this->launchFailedException($e->getPrevious() ?? $e);
        }

        if (null === $prepared) {
            $this->reconcileLaunchSuccessFromArtifacts($parentRunId, $toolCallId, $lifecycleId, $projection, $plan);

            return new DeferredToolCompletionOutcome($lifecycleId);
        }

        $startedAt = Clock::get()->now();
        try {
            $this->runtimeStart->startPrepared(
                $parentRunId,
                $toolCallId,
                $plan->identity,
                $prepared,
            );
        } catch (DeferredSubagentBatchRuntimeStartFailure $e) {
            $this->batchRepository->applyLaunchFailurePreparation($parentRunId, $toolCallId, $lifecycleId);
            throw $this->launchFailedException($e->getPrevious() ?? $e);
        }

        try {
            $this->batchRepository->applyLaunchSuccessState($parentRunId, $toolCallId, $lifecycleId, $startedAt);
        } catch (\Throwable $persistFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_success_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_success_persist_failed',
                'exception_class' => $persistFailure::class,
            ]);
        }

        return new DeferredToolCompletionOutcome($lifecycleId);
    }

    private function requirePreparedChild(DeferredSubagentBatchProjectionDTO $projection): DeferredSubagentChildProjectionDTO
    {
        if (1 !== \count($projection->children)) {
            throw new ToolCallException('Prepared child reservation must contain exactly one child.', retryable: false);
        }

        return $projection->children[0];
    }

    private function assertPlanMatchesPreparedChild(
        DeferredSubagentBatchLaunchPlanDTO $plan,
        DeferredSubagentChildProjectionDTO $child,
    ): void {
        $intent = $plan->childIntent;
        if (1 !== $intent->batchIndex
            || $intent->childRunId !== $child->childRunId
            || $intent->artifactId !== $child->artifactId
            || $intent->agentName !== $child->agentName
            || $intent->task !== $child->task
            || $intent->launchModel !== $child->launchModel
            || $intent->launchReasoning !== $child->launchReasoning) {
            throw new ToolCallException('Deferred invocation reservation does not match its launch intent.', retryable: false);
        }
        if ($plan->identity->childRunId !== $child->childRunId || $plan->identity->artifactId !== $child->artifactId) {
            throw new ToolCallException('Deferred invocation reservation does not match its launch intent.', retryable: false);
        }
    }

    /**
     * Fresh DB read of interruption intent after reserve.
     *
     * @phpstan-impure
     */
    private function hasInterruptionIntent(string $lifecycleId): bool
    {
        $projection = $this->batchRepository->findByLifecycleId($lifecycleId);

        return null !== $projection && null !== $projection->interruptionKind;
    }

    private function launchFailedException(\Throwable $cause): ToolCallException
    {
        $detail = DiagnosticMessageSanitizer::sanitize($cause->getMessage());
        $message = '' === $detail
            ? 'Subagent batch launch failed.'
            : \sprintf('Subagent batch launch failed: %s', $detail);

        return new ToolCallException($message, retryable: false, previous: $cause);
    }

    private function reconcileLaunchSuccessFromArtifacts(
        string $parentRunId,
        string $toolCallId,
        string $lifecycleId,
        DeferredSubagentBatchProjectionDTO $projection,
        DeferredSubagentBatchLaunchPlanDTO $plan,
    ): void {
        $launched = $this->batchPreparation->isLaunched($parentRunId, $projection, $plan, null);
        if (!$launched) {
            return;
        }

        try {
            $this->batchRepository->applyLaunchSuccessState(
                $parentRunId,
                $toolCallId,
                $lifecycleId,
                Clock::get()->now(),
            );
        } catch (\Throwable $persistFailure) {
            $this->logger->warning('deferred_subagent_batch.launch_success_persist_failed', [
                'run_id' => $parentRunId,
                'tool_call_id' => $toolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_success_persist_failed',
                'exception_class' => $persistFailure::class,
            ]);
        }
    }
}
