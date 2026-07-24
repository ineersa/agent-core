<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;

/**
 * Thin fork adapter: snapshot/sanitize/sync-compact parent messages, then the
 * ordinary deferred single-child subagent launcher via an explicit profiled path.
 */
final class ForkExecutionService implements ForkExecutionServiceInterface
{
    public function __construct(
        private readonly DeferredSubagentBatchLaunchService $deferredBatchLaunch,
        private readonly SubagentRunMetadataReader $metadataReader,
        private readonly RunStoreInterface $parentRunStore,
        private readonly ForkSnapshotSanitizer $snapshotSanitizer,
        private readonly CompactionServiceInterface $compactionService,
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

        // 1) One parent RunStore read → immutable snapshot. Fork compaction must
        // use the canonical parent execution model; never re-resolve session/default.
        $parentState = $this->parentRunStore->get($parentRunId);
        if (null === $parentState) {
            throw new ToolCallException(\sprintf('Fork requires canonical parent run state for run_id=%s before compaction.', $parentRunId), retryable: false);
        }
        $parentModel = null !== $parentState->model ? trim($parentState->model) : '';
        if ('' === $parentModel) {
            throw new ToolCallException(\sprintf('Fork requires canonical parent run model for run_id=%s before compaction.', $parentRunId), retryable: false);
        }
        $parentMessages = $parentState->messages;
        $turnNo = $parentState->turnNo;

        // 2) Sanitize in-flight fork invocation / provider-invalid tail
        $sanitized = $this->snapshotSanitizer->sanitize($parentMessages);

        // 3) Synchronously compact sanitized snapshot via existing compaction service
        //    BEFORE any deferred batch reservation. Child model/thinking overrides
        //    are intentionally applied only after this step (in preparation).
        $compactResult = $this->compactionService->compactMessages(
            runId: $parentRunId,
            turnNo: $turnNo,
            messages: $sanitized,
            trigger: 'fork',
            activeModel: $parentModel,
        );

        if ($compactResult->isFailure()) {
            $detail = $compactResult->failureMessage ?? $compactResult->failureReason ?? 'unknown';
            throw new ToolCallException(\sprintf('Fork compaction failed before child launch: %s', $detail), retryable: false);
        }

        // 4) Explicit required single-child profiled deferred launch (no optional generic profile).
        $profile = new DeferredSubagentSingleChildLaunchProfileDTO(
            definition: ForkInternalAgentDefinition::create($modelOverride),
            artifactKind: AgentArtifactKindEnum::Fork,
            displayAgentName: 'fork',
            inheritedMessages: $compactResult->messages,
            reasoningOverride: $reasoningOverride,
        );

        return $this->deferredBatchLaunch->launchSingleChildProfile(
            $parentRunId,
            $task,
            $profile,
        );
    }
}
