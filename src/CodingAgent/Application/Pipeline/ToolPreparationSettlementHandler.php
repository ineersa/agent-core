<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Application\Bash\NativeBashIdentity;
use Ineersa\CodingAgent\Application\Message\DTO\MarkObsoleteDeferredCompletionDTO;
use Ineersa\CodingAgent\Application\Message\DTO\PrepareNativeBashLaunchDTO;
use Ineersa\CodingAgent\Application\Message\DTO\SettleUnusedToolPreparationDTO;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
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
        private DeferredToolCompletionRepository $deferred,
        private DeferredSubagentBatchRepository $childBatches,
        private ChildInvocationLifecycleService $childLifecycle,
        private ProcessStore $processStore,
        private ToolPayloadStoreInterface $payloads,
        private ToolBatchStoreInterface $batches,
        private ValidatorInterface $validator,
        private NormalizerInterface&DenormalizerInterface $serializer,
        private BackgroundProcessManager $backgroundProcesses,
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

        $shellSettlement = $this->prepareFromShellExecutionEnd($context);
        if (null !== $shellSettlement) {
            $actions[] = new DispatchLocalMetadataMessageDTO($shellSettlement);
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
            if (null === $action->expectedOldAttempt || null === $action->expectedOldIdempotencyKey) {
                throw new \RuntimeException('Deferred preparation revision requires the captured old identity.');
            }
            $this->deferred->reviseUnusedPreparation(
                $action->revisedCorrelation,
                $action->revisedBodyReference,
                $action->expectedOldAttempt,
                $action->expectedOldIdempotencyKey,
            );
        }

        if (null !== $action->revisedNativeLaunch) {
            if (null === $action->expectedOldAttempt || null === $action->expectedOldIdempotencyKey) {
                throw new \RuntimeException('Native preparation revision requires the captured old identity.');
            }
            $launch = $action->revisedNativeLaunch;
            $old = $this->backgroundProcesses->findPreparedLaunch(
                $launch->runId,
                $launch->toolCallId,
                $launch->turnNo,
                $launch->stepId,
                $action->expectedOldAttempt,
                $action->expectedOldIdempotencyKey,
            );
            if (null !== $old) {
                $this->processStore->deleteUnusedPreparedRecord($old->id, $action->runId);
            }
            $this->backgroundProcesses->prepareCapturedNativeLaunch(
                $launch->runId,
                $launch->toolCallId,
                $launch->command,
                $launch->logPath,
                $launch->statusPath,
                $launch->backgroundRequested,
            );
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
            || $metadata->reference->stepId !== $action->stepId
            || $metadata->reference->toolCallId !== $action->toolCallId) {
            throw new \RuntimeException('Obsolete deferred settlement does not match its captured identity.');
        }
        $this->deferred->markCompleted($action->deferredId);
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function prepareNativeLaunch(PrepareNativeBashLaunchDTO $action, VerifiedTransitionDTO $pending): void
    {
        $this->assertCaptured($action, $pending);
        $this->validate($action);
        $expected = NativeBashIdentity::prefix(
            $action->runId,
            $action->turnNo,
            $action->stepId,
            $action->toolCallId,
            $action->attempt,
            $action->idempotencyKey,
        );
        if (!str_ends_with($action->statusPath, '/'.$expected.'.status')
            || !str_ends_with($action->logPath, '/'.$expected.'.log')) {
            throw new \RuntimeException('Captured native Bash paths do not match the invocation identity.');
        }
        $this->backgroundProcesses->prepareCapturedNativeLaunch(
            $action->runId,
            $action->toolCallId,
            $action->command,
            $action->logPath,
            $action->statusPath,
            $action->backgroundRequested,
        );
    }

    public function supports(object $action): bool
    {
        return $action instanceof SettleUnusedToolPreparationDTO
            || $action instanceof MarkObsoleteDeferredCompletionDTO
            || $action instanceof PrepareNativeBashLaunchDTO;
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
            $prepared = $this->exactPreparedNative($result);
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

        // Require original ToolCallRequested short-circuit approval evidence.
        $payload = $action->answer->requestPayload;
        if ((!isset($payload['hook_id']) && !isset($payload['hook_class']))
            || !isset($payload['approval_context'])) {
            return null;
        }

        $storedCall = $this->batches->loadCall($action->runId, $action->turnNo, $action->stepId, $toolCallId);
        if (null === $storedCall) {
            return null;
        }

        $batch = $this->childBatches->findByParentRunAndToolCall($action->runId, $toolCallId);
        if (null !== $batch && DeferredSubagentBatchLaunchStatusEnum::Reserved !== $batch->launchStatus) {
            return null;
        }

        $preparedNative = $this->backgroundProcesses->findPreparedLaunch(
            $action->runId,
            $toolCallId,
            $storedCall->turnNo(),
            $storedCall->stepId(),
            $storedCall->attempt(),
            $storedCall->idempotencyKey(),
        );
        if (null !== $preparedNative) {
            $owned = $this->processStore->findPreparedByRecordId($preparedNative->id, $action->runId, $toolCallId);
            if (null !== $owned && (null !== $owned->pid || null !== $owned->startedAt)) {
                return null;
            }
        }

        $authorized = $storedCall->withAuthorizedHumanAnswer($action->answer);
        $revisedCorrelation = null;
        $revisedBodyReference = null;
        $metadata = $this->deferred->findMetadataByRunAndToolCall($action->runId, $toolCallId);
        if (null !== $metadata) {
            if ('pending' !== $metadata->status) {
                return null;
            }
            if (null !== $metadata->readyAt || null !== $metadata->backgroundedAt || null !== $metadata->acceptedResultReference) {
                // Execution identity is immutable once callbacks or results can arrive.
                return null;
            }
            $existing = $this->deferred->findByDeferredId($metadata->deferredId);
            if (null === $existing) {
                return null;
            }
            $revisedCorrelation = ToolCallResultFactory::correlationFromExecuteToolCall($authorized, $existing->deferredId);
            $revisedBodyReference = $this->payloads->publishCorrelation($revisedCorrelation);
        }

        $revisedNativeLaunch = null;
        if ('bash' === $authorized->toolName) {
            $rawCommand = $authorized->args['command'] ?? null;
            $command = \is_string($rawCommand) ? trim($rawCommand) : '';
            if ('' === $command) {
                return null;
            }
            if (null === $preparedNative) {
                // Approval revision only rewrites an already-prepared unused native row.
                return null;
            }
            $paths = $this->backgroundProcesses->nativePathsForIdentity(
                $authorized->runId(),
                $authorized->turnNo(),
                $authorized->stepId(),
                $authorized->toolCallId,
                $authorized->attempt(),
                $authorized->idempotencyKey(),
            );
            $revisedNativeLaunch = new PrepareNativeBashLaunchDTO(
                runId: $authorized->runId(),
                turnNo: $authorized->turnNo(),
                stepId: $authorized->stepId(),
                toolCallId: $authorized->toolCallId,
                attempt: $authorized->attempt(),
                idempotencyKey: $authorized->idempotencyKey(),
                command: $command,
                logPath: $paths['log_path'],
                statusPath: $paths['status_path'],
                backgroundRequested: true === ($authorized->args['backgrounded'] ?? false),
            );
        }

        if (null === $revisedCorrelation && null === $revisedNativeLaunch) {
            return null;
        }

        return new SettleUnusedToolPreparationDTO(
            runId: $action->runId,
            turnNo: $action->turnNo,
            stepId: $action->stepId,
            revisedCorrelation: $revisedCorrelation,
            revisedBodyReference: $revisedBodyReference,
            revisedToolCallId: $toolCallId,
            expectedOldAttempt: $storedCall->attempt(),
            expectedOldIdempotencyKey: $storedCall->idempotencyKey(),
            revisedNativeLaunch: $revisedNativeLaunch,
        );
    }

    private function prepareFromShellExecutionEnd(AfterTurnCommitHookContext $context): ?SettleUnusedToolPreparationDTO
    {
        $codec = new ToolExecutionEndPayloadCodec($this->serializer);
        $unusedNativeRecordIds = [];
        $runId = null;
        $turnNo = null;
        $stepId = null;
        foreach ($context->events as $event) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $event->type) {
                continue;
            }
            $result = $codec->fromEventPayload($event->payload);
            if ('bash' !== $this->toolName($result) || !\is_array($result->result) || !\array_key_exists('standalone', $result->result)) {
                continue;
            }
            if (!$this->isUnusedPreparationEvidence($result)) {
                continue;
            }
            $prepared = $this->exactPreparedNative($result);
            if (null === $prepared || null !== $prepared->pid || null !== $prepared->startedAt) {
                continue;
            }
            $runId ??= $result->runId();
            $turnNo ??= $result->turnNo();
            $stepId ??= $result->stepId();
            if ($runId !== $result->runId() || $turnNo !== $result->turnNo() || $stepId !== $result->stepId()) {
                throw new \RuntimeException('Direct-shell unused settlement mixed distinct owner identities.');
            }
            $unusedNativeRecordIds[] = $prepared->id;
        }

        if (null === $runId || null === $turnNo || null === $stepId || [] === $unusedNativeRecordIds) {
            return null;
        }

        return new SettleUnusedToolPreparationDTO(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            unusedNativeRecordIds: array_values(array_unique($unusedNativeRecordIds)),
        );
    }

    private function exactPreparedNative(ToolCallResult $result): ?\Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO
    {
        $prepared = $this->backgroundProcesses->findPreparedLaunch(
            $result->runId(),
            $result->toolCallId,
            $result->turnNo(),
            $result->stepId(),
            $result->attempt(),
            $result->idempotencyKey(),
        );
        if (null === $prepared) {
            return null;
        }

        return $this->processStore->findPreparedByRecordId($prepared->id, $result->runId(), $result->toolCallId);
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

        return true === ($details['execution_not_started'] ?? false);
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
