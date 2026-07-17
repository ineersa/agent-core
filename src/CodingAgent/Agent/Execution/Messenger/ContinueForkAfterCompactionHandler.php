<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Messenger;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\ForkDeferredChildPreparationStrategyFactory;
use Ineersa\CodingAgent\Agent\Execution\ForkInternalAgentDefinition;
use Ineersa\CodingAgent\Agent\Execution\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkLocalCompactionSessionService;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class ContinueForkAfterCompactionHandler
{
    public function __construct(
        private SubagentRunMetadataReader $metadataReader,
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredSubagentBatchLaunchService $batchLaunch,
        private ForkDeferredChildPreparationStrategyFactory $forkStrategyFactory,
        private ForkLocalCompactionSessionService $localSessionService,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ContinueForkAfterCompactionMessage $message): void
    {
        $metadata = $this->metadataReader->readRunStartedMetadata($message->forkLocalRunId);
        $session = \is_array($metadata['session'] ?? null) ? $metadata['session'] : null;
        if (!\is_array($session) || ForkLocalCompactionSessionService::SESSION_KIND !== ($session['kind'] ?? null)) {
            return;
        }

        $parentRunId = \is_string($session['parent_run_id'] ?? null) ? $session['parent_run_id'] : '';
        $parentToolCallId = \is_string($session['parent_tool_call_id'] ?? null) ? $session['parent_tool_call_id'] : '';
        $lifecycleId = \is_string($session['lifecycle_id'] ?? null) ? $session['lifecycle_id'] : '';
        $task = \is_string($session['task'] ?? null) ? $session['task'] : '';
        $modelOverride = \is_string($session['model_override'] ?? null) ? $session['model_override'] : null;
        $reasoningOverride = \is_string($session['reasoning_override'] ?? null) ? $session['reasoning_override'] : null;

        if ('' === $parentRunId || '' === $parentToolCallId || '' === $lifecycleId || '' === $task) {
            $this->logger->warning('fork_local_compaction.continuation_missing_correlation', [
                'fork_local_run_id' => $message->forkLocalRunId,
                'component' => 'agent.execution',
                'event_type' => 'fork_local_compaction.continuation_missing_correlation',
            ]);
            $this->localSessionService->cleanupBestEffort($message->forkLocalRunId, $parentRunId, $parentToolCallId);

            return;
        }

        try {
            if ($message->success) {
                $this->continueSuccess(
                    parentRunId: $parentRunId,
                    parentToolCallId: $parentToolCallId,
                    task: $task,
                    modelOverride: $modelOverride,
                    reasoningOverride: $reasoningOverride,
                    forkLocalRunId: $message->forkLocalRunId,
                );
            } else {
                $this->failReserved(
                    parentRunId: $parentRunId,
                    parentToolCallId: $parentToolCallId,
                    lifecycleId: $lifecycleId,
                    failureReason: $message->failureReason ?? 'compaction_hard_failure',
                );
            }
        } finally {
            $this->localSessionService->cleanupBestEffort($message->forkLocalRunId, $parentRunId, $parentToolCallId);
        }
    }

    private function continueSuccess(
        string $parentRunId,
        string $parentToolCallId,
        string $task,
        ?string $modelOverride,
        ?string $reasoningOverride,
        string $forkLocalRunId,
    ): void {
        $batch = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);
        if (null === $batch) {
            return;
        }
        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $batch->launchStatus
            || DeferredSubagentBatchLaunchStatusEnum::Failed === $batch->launchStatus) {
            return;
        }

        $launchTask = new ForkLaunchTaskDTO(
            task: $task,
            modelOverride: $modelOverride,
            reasoningOverride: $reasoningOverride,
            forkLocalRunId: $forkLocalRunId,
        );
        $profile = new DeferredSubagentSingleChildLaunchProfileDTO(
            definition: ForkInternalAgentDefinition::create(),
            artifactKind: AgentArtifactKindEnum::Fork,
            preparationStrategy: $this->forkStrategyFactory->create($launchTask),
            displayAgentName: 'fork',
        );

        $this->batchLaunch->continueReserved(
            $parentRunId,
            $parentToolCallId,
            [new SubagentTaskDTO(agent: 'fork', task: $task)],
            ChildRunBatchExecutionModeEnum::Single,
            $profile,
        );
    }

    private function failReserved(
        string $parentRunId,
        string $parentToolCallId,
        string $lifecycleId,
        string $failureReason,
    ): void {
        $batch = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);
        if (null === $batch) {
            return;
        }

        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $batch->launchStatus) {
            return;
        }

        if (DeferredSubagentBatchLaunchStatusEnum::Failed !== $batch->launchStatus) {
            $this->batchRepository->applyLaunchFailurePreparation($parentRunId, $parentToolCallId, $lifecycleId);
            $batch = $this->batchRepository->findByParentRunAndToolCall($parentRunId, $parentToolCallId);
            if (null === $batch) {
                return;
            }
        }

        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        $presentation = 'Fork prelaunch compaction failed: '.$failureReason;
        $errorEnvelope = [
            'error' => [
                'type' => ToolCallException::class,
                'message' => $presentation,
                'retryable' => false,
                'hint' => null,
            ],
            'details' => [
                'error_type' => ToolCallException::class,
                'retryable' => false,
                'hint' => null,
                'failure_reason' => $failureReason,
            ],
        ];

        // Existing dispatcher no-ops until deferred registration exists; registration listener
        // re-delivers Failed batches later via Deliver, but launch-failure without children needs
        // direct completion once registered.
        $this->completionDispatcher->dispatchCompletion(
            lifecycleId: $lifecycleId,
            parentRunId: $parentRunId,
            parentToolCallId: $parentToolCallId,
            expectedProjectionVersion: $batch->projectionVersion,
            presentation: $presentation,
            isError: true,
            errorEnvelope: $errorEnvelope,
        );
    }
}
