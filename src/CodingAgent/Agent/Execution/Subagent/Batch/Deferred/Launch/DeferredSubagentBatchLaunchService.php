<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\DiagnosticMessageSanitizer;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkInternalAgentDefinition;
use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tool\Arguments\ForkArgumentsDTO;
use Ineersa\CodingAgent\Tool\DTO\SubagentArgumentsDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Durable idempotent one-child launch using deferred batch supervision.
 *
 * Owner preparation reserves batch/child metadata before ExecuteToolCall
 * dispatch. The worker consumes that projection and never reconstructs
 * reservation eligibility.
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
        private readonly ChildInvocationLifecycleService $childLifecycle,
        private readonly DeferredSubagentBatchIdentityFactory $identityFactory,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Owner-side metadata reservation before ExecuteToolCall dispatch.
     *
     * Builds identity/intent and reserves the batch/child only. Compaction,
     * launch-input resolution, and AgentRunner.start remain on the worker.
     */
    public function prepare(ExecuteToolCall $call): DeferredSubagentBatchProjectionDTO
    {
        if (!\in_array($call->toolName, ['subagent', 'fork'], true)) {
            throw new ToolCallException('Deferred child preparation requires a subagent or fork invocation.', retryable: false);
        }

        $parentRunId = $call->runId();
        $toolCallId = $call->toolCallId;
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $existing = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null !== $existing) {
            if ($existing->lifecycleId !== $lifecycleId
                || $existing->parentTurnNo !== $call->turnNo()
                || $existing->parentOrderIndex !== $call->orderIndex) {
                throw new ToolCallException('Prepared child reservation does not match its canonical batch invocation.', retryable: false);
            }
            if (DeferredSubagentBatchLaunchStatusEnum::Failed === $existing->launchStatus) {
                throw new ToolCallException('Subagent batch launch previously failed for this tool call.', retryable: false);
            }

            // Already prepared or launched for this exact invocation: reuse it.
            return $existing;
        }

        $plan = 'fork' === $call->toolName
            ? $this->ownerForkPlan($call)
            : $this->ownerSubagentPlan($call);
        $deadlineAt = Clock::get()->now()->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        return $this->childLifecycle->reserveBatch(
            lifecycleId: $plan->lifecycleId,
            parentRunId: $parentRunId,
            parentTurnNo: $call->turnNo(),
            parentToolCallId: $toolCallId,
            parentOrderIndex: $call->orderIndex,
            deadlineAt: $deadlineAt,
            childIntent: $plan->childIntent,
            parentModel: $plan->parentModel,
        );
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
        $projection = $this->requirePreparedProjection($parentRunId, $toolCallId, $toolContext->turnNo(), $toolContext->orderIndex());
        $child = $this->requirePreparedChild($projection);
        if (trim($agentName) !== $child->agentName || trim($task) !== $child->task) {
            throw new ToolCallException('Deferred invocation reservation does not match its launch intent.', retryable: false);
        }

        $plan = $this->batchPreparation->buildLaunchPlan(
            $parentRunId,
            $toolCallId,
            $child->agentName,
            $child->task,
            parentModel: $projection->parentModel ?? $toolContext->parentModel(),
            launchContext: $toolContext->launchContext(),
        );
        $this->assertPlanMatchesPreparedChild($plan, $child);

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
        $projection = $this->requirePreparedProjection($parentRunId, $toolCallId, $toolContext->turnNo(), $toolContext->orderIndex());
        $child = $this->requirePreparedChild($projection);
        if ($task !== $child->task || $profile->displayAgentName !== $child->agentName) {
            throw new ToolCallException('Deferred invocation reservation does not match its launch intent.', retryable: false);
        }

        $plan = $this->batchPreparation->buildSingleChildProfiledLaunchPlan(
            $parentRunId,
            $toolCallId,
            $task,
            $profile,
            parentModel: $projection->parentModel ?? $toolContext->parentModel(),
            launchContext: $toolContext->launchContext(),
        );
        $this->assertPlanMatchesPreparedChild($plan, $child);

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

    public function requirePreparedProjection(
        string $parentRunId,
        string $toolCallId,
        int $turnNo,
        int $orderIndex,
    ): DeferredSubagentBatchProjectionDTO {
        $lifecycleId = $this->identityFactory->batchLifecycleId($parentRunId, $toolCallId);
        $projection = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);
        if (null === $projection) {
            throw new ToolCallException('Owner-prepared child reservation is missing for this invocation.', retryable: false);
        }
        if ($projection->lifecycleId !== $lifecycleId
            || $projection->parentTurnNo !== $turnNo
            || $projection->parentOrderIndex !== $orderIndex) {
            throw new ToolCallException('Prepared child reservation does not match its active tool context.', retryable: false);
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
            throw $this->launchFailedException($e->getPrevious() ?? $e);
        }

        try {
            $this->childLifecycle->publishLaunchSuccessState($parentRunId, $toolCallId, $lifecycleId, $startedAt);
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

    private function ownerSubagentPlan(ExecuteToolCall $call): DeferredSubagentBatchLaunchPlanDTO
    {
        $arguments = $this->decodeArguments($call->args, SubagentArgumentsDTO::class);
        if (!$arguments instanceof SubagentArgumentsDTO) {
            throw new ToolCallException('subagent arguments are invalid.', retryable: false);
        }

        return $this->batchPreparation->buildLaunchPlan(
            $call->runId(),
            $call->toolCallId,
            trim($arguments->agent),
            trim($arguments->task),
            parentModel: $call->parentModel,
            // Owner metadata reservation does not resolve immutable launch input.
            launchContext: null,
        );
    }

    private function ownerForkPlan(ExecuteToolCall $call): DeferredSubagentBatchLaunchPlanDTO
    {
        $arguments = $this->decodeArguments($call->args, ForkArgumentsDTO::class);
        if (!$arguments instanceof ForkArgumentsDTO) {
            throw new ToolCallException('fork arguments are invalid.', retryable: false);
        }

        $model = null === $arguments->model ? null : trim($arguments->model);
        $thinking = null === $arguments->thinking ? null : trim($arguments->thinking);
        $profile = new DeferredSubagentSingleChildLaunchProfileDTO(
            definition: ForkInternalAgentDefinition::create('' === $model ? null : $model),
            artifactKind: AgentArtifactKindEnum::Fork,
            displayAgentName: 'fork',
            // Metadata-only owner plan: compaction still happens on the worker.
            inheritedMessages: [],
            reasoningOverride: '' === $thinking ? null : $thinking,
        );

        return $this->batchPreparation->buildSingleChildProfiledLaunchPlan(
            $call->runId(),
            $call->toolCallId,
            trim($arguments->task),
            $profile,
            parentModel: $call->parentModel,
            // Owner metadata reservation does not resolve immutable launch input.
            launchContext: null,
        );
    }

    /**
     * @template T of object
     *
     * @param array<string, mixed> $args
     * @param class-string<T>      $class
     *
     * @return T
     */
    private function decodeArguments(array $args, string $class): object
    {
        try {
            $arguments = $this->denormalizer->denormalize($args, $class);
        } catch (SerializerExceptionInterface $error) {
            throw new ToolCallException(\sprintf('%s arguments are invalid.', basename(str_replace('\\', '/', $class))), retryable: false, previous: $error);
        }
        if (!$arguments instanceof $class) {
            throw new ToolCallException(\sprintf('%s arguments are invalid.', basename(str_replace('\\', '/', $class))), retryable: false);
        }
        $errors = $this->validator->validate($arguments);
        if (0 !== \count($errors)) {
            throw new ToolCallException((string) $errors->get(0)->getMessage(), retryable: false, previous: new ValidationFailedException($arguments, $errors));
        }

        return $arguments;
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
            $this->childLifecycle->publishLaunchSuccessState(
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
