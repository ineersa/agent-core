<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Fork\ForkLocalCompactionSessionService;
use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSanitizer;
use Ineersa\CodingAgent\Config\ModelResolver;

final class ForkExecutionService implements ForkExecutionServiceInterface
{
    public function __construct(
        private readonly DeferredSubagentBatchLaunchService $deferredBatchLaunch,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly ForkDeferredChildPreparationStrategyFactory $forkStrategyFactory,
        private readonly StackToolExecutionContextAccessor $toolContextAccessor,
        private readonly RunStoreInterface $runStore,
        private readonly ForkSnapshotSanitizer $snapshotSanitizer,
        private readonly ForkLocalCompactionSessionService $localCompactionSession,
        private readonly AgentRunnerInterface $agentRunner,
        private readonly ModelResolver $modelResolver,
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome {
        if ($this->metadataReader->isAgentChild($parentRunId)) {
            throw new ToolCallException('Nested fork launches are not supported.', retryable: false);
        }

        // Capture correlation while tool stack is active; async continuation cannot re-enter it.
        $toolContext = $this->toolContextAccessor->requireCurrent();
        if ($parentRunId !== $toolContext->runId()) {
            throw new ToolCallException('Fork parent run id does not match active tool context.', retryable: false);
        }
        $parentToolCallId = $toolContext->toolCallId();

        $launchTask = new ForkLaunchTaskDTO($task, $modelOverride, $reasoningOverride);
        $profile = new DeferredSubagentSingleChildLaunchProfileDTO(
            definition: ForkInternalAgentDefinition::create(),
            artifactKind: AgentArtifactKindEnum::Fork,
            preparationStrategy: $this->forkStrategyFactory->create($launchTask),
            displayAgentName: 'fork',
        );

        $tasks = [new SubagentTaskDTO(agent: 'fork', task: $task)];
        $outcome = $this->deferredBatchLaunch->reserveOnly(
            $parentRunId,
            $tasks,
            ChildRunBatchExecutionModeEnum::Single,
            $profile,
        );

        $parentState = $this->runStore->get($parentRunId);
        $sanitized = null !== $parentState
            ? $this->snapshotSanitizer->sanitize($parentState->messages)
            : [];

        $parentMetadata = $this->metadataReader->readRunStartedMetadata($parentRunId) ?? [];
        $model = $this->resolveParentModel($parentRunId, $parentMetadata, $modelOverride);
        $reasoning = $this->resolveParentReasoning($parentRunId, $parentMetadata, $reasoningOverride);

        $localRunId = $this->localCompactionSession->createSeededSession(
            correlation: [
                'parent_run_id' => $parentRunId,
                'parent_tool_call_id' => $parentToolCallId,
                'lifecycle_id' => $outcome->deferredId,
                'task' => $task,
                'model_override' => $modelOverride,
                'reasoning_override' => $reasoningOverride,
            ],
            sanitizedMessages: $sanitized,
            model: $model,
            reasoning: $reasoning,
        );

        try {
            $this->agentRunner->compact($localRunId);
        } catch (\Throwable $e) {
            $this->localCompactionSession->cleanupBestEffort($localRunId, $parentRunId, $parentToolCallId);

            throw new ToolCallException('Fork prelaunch compaction dispatch failed.', retryable: false, previous: $e);
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $parentMetadata
     */
    private function resolveParentModel(string $parentRunId, array $parentMetadata, ?string $modelOverride): ?string
    {
        if (null !== $modelOverride && '' !== trim($modelOverride)) {
            return $modelOverride;
        }
        $current = $this->modelResolver->getCurrentModel($parentRunId)?->toString();
        if (null !== $current && '' !== trim($current)) {
            return $current;
        }
        $model = $parentMetadata['model'] ?? null;

        return \is_string($model) && '' !== trim($model) ? $model : null;
    }

    /**
     * @param array<string, mixed> $parentMetadata
     */
    private function resolveParentReasoning(string $parentRunId, array $parentMetadata, ?string $reasoningOverride): ?string
    {
        if (null !== $reasoningOverride && '' !== trim($reasoningOverride)) {
            return $reasoningOverride;
        }
        $current = $this->modelResolver->getCurrentReasoning($parentRunId);
        if ('' !== $current) {
            return $current;
        }
        $reasoning = $parentMetadata['reasoning'] ?? null;

        return \is_string($reasoning) && '' !== trim($reasoning) ? $reasoning : null;
    }
}
