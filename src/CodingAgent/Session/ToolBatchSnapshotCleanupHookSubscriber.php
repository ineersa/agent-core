<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
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
    ) {
    }

    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        foreach ($context->events as $event) {
            if (RunEventTypeEnum::ToolExecutionEnd->value === $event->type
                && \is_string($event->payload['tool_result']['tool_call_id'] ?? null)) {
                // A canonical result resolves synchronous launch failures too.
                // Approval suspension has no terminal tool result and retains input.
                $this->tryDeleteLaunchInput($context->runId, $event->payload['tool_result']['tool_call_id']);
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

        if ($this->shouldDeleteAllSnapshotsAfterTerminalCommit($context)) {
            $this->tryDeleteAllForRun($context->runId);
            try {
                $this->launchInputStore->deleteAllForRun($context->runId);
            } catch (\Throwable $exception) {
                $this->logger->warning('tool_launch_input.terminal_cleanup_failed', [
                    'run_id' => $context->runId, 'component' => 'tool_batch_snapshot_cleanup',
                    'event_type' => 'tool_launch_input.terminal_cleanup_failed', 'exception_class' => $exception::class,
                ]);
            }
        }

        return $context;
    }

    private function tryDeleteLaunchInput(string $runId, string $toolCallId): void
    {
        try {
            $this->launchInputStore->delete($runId, $toolCallId);
        } catch (\Throwable $exception) {
            $this->logger->warning('tool_launch_input.cleanup_failed', [
                'run_id' => $runId, 'tool_call_id' => $toolCallId, 'component' => 'tool_batch_snapshot_cleanup',
                'event_type' => 'tool_launch_input.cleanup_failed', 'exception_class' => $exception::class,
            ]);
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
}
