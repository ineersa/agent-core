<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchRuntimeStartService;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Launch\DeferredForkBatchPreparationService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ForkDeferredPrelaunchFailureService;
use Ineersa\CodingAgent\Compaction\CompactionSkipReasonEnum;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\Fork\ForkSessionCopyService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ContinueForkDeferredPrelaunchHandler
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredForkBatchPreparationService $forkPreparation,
        private DeferredAgentChildBatchRuntimeStartService $runtimeStart,
        private ForkSessionCopyService $sessionCopyService,
        private ForkDeferredPrelaunchFailureService $prelaunchFailure,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ContinueForkDeferredPrelaunchMessage $message): void
    {
        $batch = $this->batchRepository->findOneBy(['lifecycleId' => $message->batchLifecycleId]);
        if (null === $batch) {
            return;
        }

        if (ForkDeferredPrelaunchPhaseEnum::ReadyForChildLaunch->value !== (string) $batch->prelaunchPhase) {
            return;
        }

        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $batch->launchStatus) {
            $this->sessionCopyService->removeForkLocalSession($message->forkLocalRunId);

            return;
        }

        $projection = $this->batchRepository->findProjectionByLifecycleId($message->batchLifecycleId);
        if (null === $projection) {
            return;
        }

        if ($this->isHardCompactionFailure($message->terminalEventType, $message->terminalPayload)) {
            $this->prelaunchFailure->failDeferredForkTool(
                lifecycleId: $batch->lifecycleId,
                parentRunId: $batch->parentRunId,
                parentToolCallId: $batch->parentToolCallId,
                projectionVersion: $batch->projectionVersion,
                forkLocalRunId: $message->forkLocalRunId,
                reason: (string) ($message->terminalPayload['reason'] ?? 'compaction_failed'),
            );

            return;
        }

        $child = $projection->children[0] ?? null;
        if (null === $child) {
            return;
        }

        $plan = $this->forkPreparation->buildLaunchPlan(
            $batch->parentRunId,
            $batch->parentToolCallId,
            [new \Ineersa\CodingAgent\Agent\Execution\Fork\ForkLaunchTaskDTO(
                task: $child->task,
                modelOverride: $child->definitionModel,
                reasoningOverride: $child->reasoningOverride,
            )],
            $batch->executionMode,
        );

        try {
            $prepared = $this->forkPreparation->preparePendingChildrenAfterPrelaunch(
                $batch->parentRunId,
                $projection,
                $plan,
                $message->forkLocalRunId,
            );
        } catch (\Throwable $e) {
            $this->prelaunchFailure->failDeferredForkTool(
                lifecycleId: $batch->lifecycleId,
                parentRunId: $batch->parentRunId,
                parentToolCallId: $batch->parentToolCallId,
                projectionVersion: $batch->projectionVersion,
                forkLocalRunId: $message->forkLocalRunId,
                reason: 'child_prepare_failed',
                previous: $e,
            );

            return;
        }

        if ([] === $prepared) {
            return;
        }

        $this->runtimeStart->startPreparedInOrder(
            $batch->parentRunId,
            $batch->parentToolCallId,
            $plan->identities,
            $prepared,
        );

        $this->batchRepository->applyLaunchSuccessState(
            $batch->parentRunId,
            $batch->parentToolCallId,
            $batch->lifecycleId,
            new \DateTimeImmutable(),
            [1],
        );

        $this->sessionCopyService->removeForkLocalSession($message->forkLocalRunId);
    }

    /**
     * @param array<string, mixed> $terminalPayload
     */
    private function isHardCompactionFailure(string $eventType, array $terminalPayload): bool
    {
        if (RunEventTypeEnum::ContextCompactionFailed->value !== $eventType) {
            return false;
        }

        if (true === ($terminalPayload['messages_replaced'] ?? null)) {
            return true;
        }

        $reason = $terminalPayload['reason'] ?? null;
        if (!\is_string($reason)) {
            return true;
        }

        return null === CompactionSkipReasonEnum::tryFrom($reason);
    }
}
