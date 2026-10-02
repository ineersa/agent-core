<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentSingleChildLaunchProfileDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;
use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;

/**
 * Thin fork adapter: sanitize/sync-compact the owner-prepared immutable message
 * snapshot, then the ordinary deferred single-child subagent launcher via an
 * explicit profiled path. No parent archive replay at this boundary.
 */
final class ForkExecutionService implements ForkExecutionServiceInterface
{
    public function __construct(
        private readonly DeferredSubagentBatchLaunchService $deferredBatchLaunch,
        private readonly RunRelationshipReaderInterface $relationshipReader,
        private readonly ForkSnapshotSanitizer $snapshotSanitizer,
        private readonly CompactionServiceInterface $compactionService,
    ) {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ToolLaunchContextDTO $launchContext,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome {
        try {
            $this->relationshipReader->requireKnownTopLevel($parentRunId);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage(), retryable: false);
        }

        if (!$launchContext->isFork()) {
            throw new ToolCallException(\sprintf('Fork requires owner-prepared immutable fork launch context for run_id=%s.', $parentRunId), retryable: false);
        }
        if ($launchContext->producingRunId !== $parentRunId) {
            throw new ToolCallException(\sprintf('Fork launch context producing run %s does not match parent run %s.', $launchContext->producingRunId, $parentRunId), retryable: false);
        }

        $parentModel = trim($launchContext->producingModel);
        if ('' === $parentModel) {
            throw new ToolCallException(\sprintf('Fork requires owner-prepared producing model for run_id=%s before compaction.', $parentRunId), retryable: false);
        }

        // 1) Sanitize in-flight fork invocation / provider-invalid tail from the
        //    immutable owner-prepared snapshot (not a live parent state lookup).
        $sanitized = $this->snapshotSanitizer->sanitize($launchContext->forkMessages);

        // 2) Synchronously compact sanitized snapshot via existing compaction service
        //    BEFORE any deferred batch reservation. Child model/thinking overrides
        //    are intentionally applied only after this step (in preparation).
        $compactResult = $this->compactionService->compactMessages(
            runId: $parentRunId,
            turnNo: $launchContext->producingTurnNo,
            messages: $sanitized,
            trigger: 'fork',
            activeModel: $parentModel,
        );

        if ($compactResult->isFailure()) {
            $detail = $compactResult->failureMessage ?? $compactResult->failureReason ?? 'unknown';
            throw new ToolCallException(\sprintf('Fork compaction failed before child launch: %s', $detail), retryable: false);
        }

        // 3) Explicit required single-child profiled deferred launch (no optional generic profile).
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
