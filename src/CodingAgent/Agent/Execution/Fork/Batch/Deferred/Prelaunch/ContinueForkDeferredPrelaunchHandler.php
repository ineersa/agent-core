<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchRuntimeStartService;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Launch\DeferredForkBatchPreparationService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
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

        $projection = $this->batchRepository->findProjectionByLifecycleId($message->batchLifecycleId);
        if (null === $projection) {
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
                reasoningOverride: null,
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
            $this->batchRepository->applyForkPrelaunchPhase(
                $batch->parentRunId,
                $batch->parentToolCallId,
                ForkDeferredPrelaunchPhaseEnum::Failed,
            );
            $this->sessionCopyService->removeForkLocalSession($message->forkLocalRunId);
            $this->logger->warning('fork_deferred_prelaunch.child_prepare_failed', [
                'batch_lifecycle_id' => $message->batchLifecycleId,
                'fork_local_run_id' => $message->forkLocalRunId,
                'component' => 'agent.execution.fork',
                'event_type' => 'fork_deferred_prelaunch.child_prepare_failed',
                'exception_class' => $e::class,
            ]);

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
}
