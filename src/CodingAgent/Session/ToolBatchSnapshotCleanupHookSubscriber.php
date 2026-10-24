<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Psr\Log\LoggerInterface;

/**
 * Post-commit cleanup for transient tool-batch snapshot files.
 *
 * Runs only after RunCommit succeeded (AfterTurnCommit hook). Cleanup failures
 * are intentional local degradation: structured warning logs, no commit rollback.
 */
final class ToolBatchSnapshotCleanupHookSubscriber implements HookSubscriberInterface
{
    public function __construct(
        private readonly ToolBatchStoreInterface $toolBatchStore,
        private readonly LoggerInterface $logger,
        private readonly ToolLaunchInputStoreInterface $launchInputStore,
        private readonly ToolPayloadStoreInterface $payloads,
        private readonly RunLockManager $locks,
        private readonly DeferredToolCompletionRepositoryInterface $deferred,
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        return $this->locks->synchronized($context->runId, fn () => $this->cleanupCommitted($context));
    }

    private function cleanupCommitted(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        $launchInputIds = [];
        foreach ($context->events as $event) {
            if (RunEventTypeEnum::ToolExecutionEnd->value === $event->type
                && \is_string($event->payload['tool_result']['tool_call_id'] ?? null)) {
                // LlmStepResultHandler publishes launch input only for these
                // tools. Terminal results include synchronous launch failures;
                // approval suspension has no terminal event and retains input.
                if (\in_array($event->payload['tool_result']['result']['tool_name'] ?? null, ['fork', 'subagent'], true)) {
                    $launchInputIds[] = $event->payload['tool_result']['tool_call_id'];
                }
                // Direct shell outcomes have no SQL batch to trigger cleanup,
                // including non-standalone shells while a model turn is active.
                if (\array_key_exists('standalone', $event->payload['tool_result']['result'] ?? [])) {
                    try {
                        $this->payloads->deleteCall($context->runId, $event->payload['tool_result']['tool_call_id']);
                    } catch (\Throwable $exception) {
                        $this->logger->warning('tool_payload.shell_cleanup_failed', ['run_id' => $context->runId, 'session_id' => $context->runId, 'component' => 'tool_batch_snapshot_cleanup', 'event_type' => 'tool_payload.shell_cleanup_failed', 'exception_class' => $exception::class]);
                    }
                }
            }
            if (RunEventTypeEnum::ToolBatchCommitted->value !== $event->type) {
                continue;
            }

            $identity = $this->batchIdentityFromPayload($event->payload);
            if (null === $identity) {
                $this->logger->warning('tool_batch.snapshot_cleanup_skipped', [
                    'run_id' => $context->runId,
                    'turn_no' => $context->turnNo,
                    'component' => 'tool_batch_snapshot_cleanup',
                    'event_type' => 'tool_batch_committed',
                    'reason' => 'missing_batch_identity',
                ]);

                continue;
            }

            $this->tryDeleteBatch($context->runId, $identity['turn_no'], $identity['step_id']);
        }

        $this->tryDeleteLaunchInputs($context->runId, array_values(array_unique($launchInputIds)));

        if ($this->shouldDeleteAllSnapshotsAfterTerminalCommit($context)) {
            $this->tryDeleteAllForRun($context->runId);
            try {
                if (!$this->toolBatchStore->hasUnresolvedExecution($context->runId)) {
                    $this->launchInputStore->deleteAllForRun($context->runId);
                    // Retained deferred evidence outlives batch settlement until
                    // permanent session deletion. Ordinary unresolved work still
                    // blocks full payload wipe through hasUnresolvedExecution.
                    if ([] === $context->runState->pendingShellToolCalls && !$this->hasRetainedDeferredEvidence($context->runId)) {
                        $this->payloads->deleteAllForRun($context->runId);
                    }
                }
            } catch (\Throwable $exception) {
                $this->logger->warning('tool_launch_input.terminal_cleanup_failed', [
                    'run_id' => $context->runId, 'component' => 'tool_batch_snapshot_cleanup',
                    'event_type' => 'tool_launch_input.terminal_cleanup_failed', 'exception_class' => $exception::class,
                ]);
            }
        }

        return $context;
    }

    /** @param list<string> $toolCallIds */
    private function tryDeleteLaunchInputs(string $runId, array $toolCallIds): void
    {
        if ([] === $toolCallIds) {
            return;
        }
        try {
            $unresolved = $this->toolBatchStore->findUnresolvedToolCallIds($runId, $toolCallIds);
        } catch (\Throwable $exception) {
            $this->logger->warning('tool_launch_input.cleanup_failed', [
                'run_id' => $runId, 'session_id' => $runId, 'tool_call_count' => \count($toolCallIds), 'component' => 'tool_batch_snapshot_cleanup',
                'event_type' => 'tool_launch_input.cleanup_failed', 'exception_class' => $exception::class,
            ]);

            return;
        }
        foreach (array_diff($toolCallIds, $unresolved) as $toolCallId) {
            try {
                $this->launchInputStore->delete($runId, $toolCallId);
            } catch (\Throwable $exception) {
                $this->logger->warning('tool_launch_input.cleanup_failed', [
                    'run_id' => $runId, 'session_id' => $runId, 'tool_call_id' => $toolCallId, 'component' => 'tool_batch_snapshot_cleanup',
                    'event_type' => 'tool_launch_input.cleanup_failed', 'exception_class' => $exception::class,
                ]);
            }
        }
    }

    private function shouldDeleteAllSnapshotsAfterTerminalCommit(AfterTurnCommitHookContext $context): bool
    {
        $terminalStatuses = [
            RunStatus::Completed->value,
            RunStatus::Failed->value,
            RunStatus::Cancelled->value,
        ];

        if (!\in_array($context->status, $terminalStatuses, true)) {
            return false;
        }

        foreach ($context->events as $event) {
            if (RunEventTypeEnum::AgentEnd->value === $event->type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{turn_no: int, step_id: string}|null
     */
    private function batchIdentityFromPayload(array $payload): ?array
    {
        $turnNo = $payload['turn_no'] ?? null;
        $stepId = $payload['step_id'] ?? null;

        if (!\is_int($turnNo) || !\is_string($stepId) || '' === $stepId) {
            return null;
        }

        return ['turn_no' => $turnNo, 'step_id' => $stepId];
    }

    private function tryDeleteBatch(string $runId, int $turnNo, string $stepId): void
    {
        try {
            $this->toolBatchStore->delete($runId, $turnNo, $stepId);
        } catch (\Throwable $throwable) {
            $this->logger->warning('tool_batch.snapshot_delete_failed', [
                'run_id' => $runId,
                'turn_no' => $turnNo,
                'step_id' => $stepId,
                'component' => 'tool_batch_snapshot_cleanup',
                'event_type' => 'tool_batch_committed',
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function tryDeleteAllForRun(string $runId): void
    {
        try {
            $this->toolBatchStore->deleteAllForRun($runId);
        } catch (\Throwable $throwable) {
            $this->logger->warning('tool_batch.snapshot_delete_all_failed', [
                'run_id' => $runId,
                'component' => 'tool_batch_snapshot_cleanup',
                'event_type' => 'agent_end_terminal',
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function hasRetainedDeferredEvidence(string $runId): bool
    {
        return $this->deferred->hasRetainedEvidenceForRun($runId);
    }
}
