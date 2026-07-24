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

        // 1) One parent RunStore read → immutable snapshot
        $parentState = $this->parentRunStore->get($parentRunId);
        $parentMessages = null !== $parentState ? $parentState->messages : [];
        $turnNo = null !== $parentState ? $parentState->turnNo : 0;

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
            activeModel: $parentState?->model,
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
