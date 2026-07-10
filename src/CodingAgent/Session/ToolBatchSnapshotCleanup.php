<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Post-commit cleanup for transient tool-batch snapshot files.
 */
final class ToolBatchSnapshotCleanup
{
    public function __construct(
        private readonly ToolBatchStoreInterface $toolBatchStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function deleteBatch(string $runId, int $turnNo, string $stepId): callable
    {
        return function () use ($runId, $turnNo, $stepId): void {
            try {
                $this->toolBatchStore->delete($runId, $turnNo, $stepId);
            } catch (\Throwable $throwable) {
                $this->logger->warning('tool_batch.snapshot_delete_failed', [
                    'run_id' => $runId,
                    'turn_no' => $turnNo,
                    'step_id' => $stepId,
                    'component' => 'tool_batch_snapshot_cleanup',
                    'error' => $throwable->getMessage(),
                ]);
            }
        };
    }

    public function deleteAllForRun(string $runId): callable
    {
        return function () use ($runId): void {
            try {
                $this->toolBatchStore->deleteAllForRun($runId);
            } catch (\Throwable $throwable) {
                $this->logger->warning('tool_batch.snapshot_delete_all_failed', [
                    'run_id' => $runId,
                    'component' => 'tool_batch_snapshot_cleanup',
                    'error' => $throwable->getMessage(),
                ]);
            }
        };
    }
}
