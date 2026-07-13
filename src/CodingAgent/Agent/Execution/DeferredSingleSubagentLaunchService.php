<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchLaunchAbortContextDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentChildRunBatchLifecyclePolicyFactory;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;

/**
 * Durable idempotent single-child launch returning deferred tool completion (Piece 3A).
 */
final class DeferredSingleSubagentLaunchService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly DeferredSingleSubagentIdentityFactory $identityFactory,
        private readonly DeferredSingleSubagentLaunchRepository $launchProjectionRepository,
        private readonly ChildRunArtifactLifecycleService $artifactLifecycle,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly ChildRunBatchLaunchService $batchLaunchService,
        private readonly SubagentChildRunBatchLifecyclePolicyFactory $lifecyclePolicyFactory,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    public function launch(
        string $parentRunId,
        string $agentName,
        string $task,
    ): DeferredToolCompletionOutcome {
        $this->launchPreparation->requireForegroundDefinition($agentName);
        $this->launchPreparation->assertDepthAllowed($parentRunId);

        $toolContext = $this->contextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Subagent parent run id does not match active tool context.', retryable: false);
        }

        $toolCallId = $toolContext->toolCallId();
        $existing = $this->launchProjectionRepository->findByParentRunAndToolCall($parentRunId, $toolCallId);

        if (null !== $existing && DeferredSingleSubagentLaunchStatusEnum::Launched === $existing->launchStatus) {
            return new DeferredToolCompletionOutcome();
        }

        if (null !== $existing && DeferredSingleSubagentLaunchStatusEnum::Failed === $existing->launchStatus) {
            throw new ToolCallException('Subagent child launch previously failed for this tool call.', retryable: false);
        }

        $ids = $this->identityFactory->forParentToolCall($parentRunId, $toolCallId);
        $deadlineAt = (new \DateTimeImmutable())->modify(\sprintf('+%d seconds', $this->agentsConfig->subagentToolTimeoutSeconds));

        if (null === $existing) {
            $definition = $this->launchPreparation->requireForegroundDefinition($agentName);

            $projection = $this->launchProjectionRepository->reserve(
                parentRunId: $parentRunId,
                parentTurnNo: $toolContext->turnNo(),
                parentToolCallId: $toolCallId,
                parentOrderIndex: $toolContext->orderIndex(),
                childRunId: $ids['childRunId'],
                artifactId: $ids['artifactId'],
                agentName: $agentName,
                task: $task,
                definitionModel: $definition->model,
                deadlineAt: $deadlineAt,
            );

            $prepared = $this->launchPreparation->prepareFromDefinition(
                $parentRunId,
                $definition,
                $agentName,
                $task,
                $ids['artifactId'],
                $ids['childRunId'],
                skipReservation: false,
            );

            return $this->dispatchLaunch($parentRunId, $toolCallId, $prepared, $projection);
        }

        // Reserved crash window: artifact may already be pending; skip re-reservation.
        $definition = $this->launchPreparation->requireForegroundDefinition($agentName);

        $prepared = $this->launchPreparation->prepareFromDefinition(
            $parentRunId,
            $definition,
            $agentName,
            $task,
            $existing->artifactId,
            $existing->childRunId,
            skipReservation: $this->artifactLifecycle->hasRegistryEntry($parentRunId, $existing->artifactId),
        );

        return $this->dispatchLaunch($parentRunId, $toolCallId, $prepared, $existing);
    }

    private function dispatchLaunch(
        string $parentRunId,
        string $toolCallId,
        ChildRun\Contract\PreparedAgentChildRunDTO $prepared,
        DeferredSingleSubagentProjectionDTO $projection,
    ): DeferredToolCompletionOutcome {
        try {
            $this->agentRunner->start($prepared->startRunInput);
            $startedAt = new \DateTimeImmutable();
            $this->artifactLifecycle->markRunning($prepared->identity);
            $this->launchProjectionRepository->markLaunched($parentRunId, $toolCallId, $startedAt);
        } catch (\Throwable $e) {
            $policy = $this->lifecyclePolicyFactory->create();
            $this->batchLaunchService->abort(
                $parentRunId,
                [$prepared->identity],
                $policy,
                $e,
                ChildRunBatchLaunchAbortContextDTO::runtimeStart(),
            );
            $this->launchProjectionRepository->markFailed($parentRunId, $toolCallId);

            throw new ToolCallException('Parallel subagent launch failed: '.$e->getMessage(), retryable: false, previous: $e);
        }

        return new DeferredToolCompletionOutcome();
    }
}
