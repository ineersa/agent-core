<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Application\Message\DTO\MarkObsoleteDeferredCompletionDTO;
use Ineersa\CodingAgent\Application\Message\DTO\SettleUnusedToolPreparationDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Captures and applies unused-preparation settlement with the verified batch commit.
 *
 * Reads and immutable correlation publication happen before the metadata
 * transaction. The message handlers perform metadata SQL only.
 */
final readonly class ToolPreparationSettlementHandler implements CoordinationActionValidatorInterface, EssentialAfterTurnHookInterface
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferred,
        private DeferredSubagentBatchRepository $childBatches,
        private ChildInvocationLifecycleService $childLifecycle,
        private ProcessStore $processStore,
        private ToolPayloadStoreInterface $payloads,
        private ValidatorInterface $validator,
    ) {
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        $actions = [];
        foreach ($context->postCommitActions as $action) {
            if ($action instanceof FinalizeToolBatchDTO) {
                $settlement = $this->prepareFromFinalize($action);
                if (null !== $settlement) {
                    $actions[] = new DispatchLocalMetadataMessageDTO($settlement);
                }
            }
        }

        return $actions;
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function settleUnused(SettleUnusedToolPreparationDTO $action, VerifiedTransitionDTO $pending): void
    {
        $this->assertCaptured($action, $pending);
        $this->validate($action);

        if (null !== $action->revisedCorrelation && null !== $action->revisedToolCallId) {
            if (null === $action->revisedBodyReference) {
                throw new \RuntimeException('Deferred preparation revision requires a published body reference.');
            }
            $this->deferred->reviseUnusedPreparation($action->revisedCorrelation, $action->revisedBodyReference);
        }

        foreach ($action->deferredIds as $deferredId) {
            $this->deferred->markCompleted($deferredId);
        }

        foreach ($action->reservedChildFailures as $failure) {
            $this->childLifecycle->applyLaunchFailurePreparation(
                $failure['parent_run_id'],
                $failure['parent_tool_call_id'],
                $failure['lifecycle_id'],
            );
        }

        foreach ($action->unusedNativeRecordIds as $recordId) {
            $this->processStore->deleteUnusedPreparedRecord($recordId, $action->runId);
        }
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function settleObsolete(MarkObsoleteDeferredCompletionDTO $action, VerifiedTransitionDTO $pending): void
    {
        $this->assertCaptured($action, $pending);
        $this->validate($action);
        $metadata = $this->deferred->findMetadataByDeferredId($action->deferredId);
        if (null === $metadata) {
            return;
        }
        if ($metadata->reference->runId !== $action->runId
            || $metadata->reference->turnNo !== $action->turnNo
            || $metadata->reference->stepId !== $action->stepId) {
            throw new \RuntimeException('Obsolete deferred settlement does not match its captured identity.');
        }
        $this->deferred->markCompleted($action->deferredId);
    }

    public function supports(object $action): bool
    {
        return $action instanceof SettleUnusedToolPreparationDTO
            || $action instanceof MarkObsoleteDeferredCompletionDTO;
    }

    public function validate(object $action): void
    {
        if (!$this->supports($action)) {
            throw new \InvalidArgumentException('Unsupported tool preparation settlement action.');
        }
        $violations = $this->validator->validate($action);
        if (0 !== \count($violations)) {
            throw new ValidationFailedException($action, $violations);
        }
    }

    private function prepareFromFinalize(FinalizeToolBatchDTO $action): ?SettleUnusedToolPreparationDTO
    {
        if (null !== $action->revisedCallId) {
            return $this->prepareApprovalRevision($action);
        }

        $result = $action->result;
        if (null === $result || $result->isHumanInputSuspension()) {
            return null;
        }

        $deferredIds = [];
        $reservedChildFailures = [];
        $unusedNativeRecordIds = [];
        $unused = $this->isUnusedPreparationEvidence($result);

        $metadata = $this->deferred->findMetadataByRunAndToolCall($result->runId(), $result->toolCallId);
        if (null !== $metadata && 'pending' === $metadata->status) {
            if (null === $metadata->backgroundedAt) {
                // Ordinary admitted results and proven unused preparations settle
                // the caller wait. Live backgrounded hosts keep physical metadata.
                $deferredIds[] = $metadata->deferredId;
            }
        }

        if ($unused && \in_array($this->toolName($result), ['subagent', 'fork', 'agent_resume'], true)) {
            $batch = $this->childBatches->findByParentRunAndToolCall($result->runId(), $result->toolCallId);
            if (null !== $batch && DeferredSubagentBatchLaunchStatusEnum::Reserved === $batch->launchStatus) {
                $reservedChildFailures[] = [
                    'parent_run_id' => $result->runId(),
                    'parent_tool_call_id' => $result->toolCallId,
                    'lifecycle_id' => $batch->lifecycleId,
                ];
            }
        }

        if ($unused && 'bash' === $this->toolName($result)) {
            $prepared = $this->processStore->findPreparedByRunAndToolCall($result->runId(), $result->toolCallId);
            if (null !== $prepared && null === $prepared->pid && null === $prepared->startedAt) {
                $unusedNativeRecordIds[] = $prepared->id;
            }
        }

        if ([] === $deferredIds && [] === $reservedChildFailures && [] === $unusedNativeRecordIds) {
            return null;
        }

        return new SettleUnusedToolPreparationDTO(
            runId: $action->runId,
            turnNo: $action->turnNo,
            stepId: $action->stepId,
            deferredIds: $deferredIds,
            reservedChildFailures: $reservedChildFailures,
            unusedNativeRecordIds: $unusedNativeRecordIds,
        );
    }

    private function prepareApprovalRevision(FinalizeToolBatchDTO $action): ?SettleUnusedToolPreparationDTO
    {
        $toolCallId = $action->revisedCallId;
        if (null === $toolCallId || null === $action->answer) {
            return null;
        }

        $metadata = $this->deferred->findMetadataByRunAndToolCall($action->runId, $toolCallId);
        if (null === $metadata || 'pending' !== $metadata->status) {
            return null;
        }
        if (null !== $metadata->readyAt || null !== $metadata->backgroundedAt || null !== $metadata->acceptedResultReference) {
            // Execution identity is immutable once callbacks or results can arrive.
            return null;
        }

        $batch = $this->childBatches->findByParentRunAndToolCall($action->runId, $toolCallId);
        if (null !== $batch && DeferredSubagentBatchLaunchStatusEnum::Reserved !== $batch->launchStatus) {
            return null;
        }

        $preparedNative = $this->processStore->findPreparedByRunAndToolCall($action->runId, $toolCallId);
        if (null !== $preparedNative && (null !== $preparedNative->pid || null !== $preparedNative->startedAt)) {
            return null;
        }

        $existing = $this->deferred->findByDeferredId($metadata->deferredId);
        if (null === $existing) {
            return null;
        }

        // Capture the revised correlation body before the metadata transaction.
        // DoctrineToolBatchStore applies withAuthorizedHumanAnswer under the same commit.
        $revisedCorrelation = new DeferredToolCompletionCorrelation(
            deferredId: $existing->deferredId,
            runId: $existing->runId,
            turnNo: $existing->turnNo,
            stepId: $existing->stepId,
            attempt: $existing->attempt + 1,
            idempotencyKey: hash('sha256', \sprintf(
                '%s|%s|%s|human|%d|%s',
                $existing->runId,
                $existing->stepId,
                $existing->toolCallId,
                $existing->attempt + 1,
                $action->answer->questionId,
            )),
            toolCallId: $existing->toolCallId,
            toolName: $existing->toolName,
            arguments: $existing->arguments,
            orderIndex: $existing->orderIndex,
            toolIdempotencyKey: $existing->toolIdempotencyKey,
            mode: $existing->mode,
            timeoutSeconds: $existing->timeoutSeconds,
            maxParallelism: $existing->maxParallelism,
            argSchema: $existing->argSchema,
            toolsRef: $existing->toolsRef,
        );
        $revisedBodyReference = $this->payloads->publishCorrelation($revisedCorrelation);

        return new SettleUnusedToolPreparationDTO(
            runId: $action->runId,
            turnNo: $action->turnNo,
            stepId: $action->stepId,
            revisedCorrelation: $revisedCorrelation,
            revisedBodyReference: $revisedBodyReference,
            revisedToolCallId: $toolCallId,
        );
    }

    private function isUnusedPreparationEvidence(ToolCallResult $result): bool
    {
        if (!\is_array($result->result)) {
            return false;
        }
        $details = $result->result['details'] ?? null;
        if (!\is_array($details)) {
            return false;
        }
        if (true === ($details['execution_not_started'] ?? false)) {
            return true;
        }
        // Preparation refusals sealed before dispatch carry this explicit marker.
        if (true === ($details['preparation_unused'] ?? false)) {
            return true;
        }

        return false;
    }

    private function toolName(ToolCallResult $result): ?string
    {
        $name = \is_array($result->result) ? ($result->result['tool_name'] ?? null) : null;

        return \is_string($name) ? $name : null;
    }

    private function assertCaptured(object $action, VerifiedTransitionDTO $pending): void
    {
        foreach ([...($pending->work['actions'] ?? []), ...($pending->work['after_turn_actions'] ?? [])] as $captured) {
            if ($captured instanceof DispatchLocalMetadataMessageDTO && serialize($captured->message) === serialize($action)) {
                return;
            }
        }

        throw new \RuntimeException('Tool preparation settlement requires its captured owner transition.');
    }
}
