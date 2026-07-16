<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\VirtualCompactionOrchestratorInterface;

/**
 * Fork virtual-compaction adapter.
 *
 * Delegates to {@see VirtualCompactionOrchestrator} using canonical compaction
 * prepare() semantics. Under-threshold contexts pass through unchanged; the
 * parent run is never mutated.
 */
final readonly class ForkSnapshotCompactor
{
    public function __construct(
        private VirtualCompactionOrchestratorInterface $virtualCompactionOrchestrator,
    ) {
    }

    /**
     * @param list<AgentMessage> $sanitized Sanitized parent messages
     */
    public function compact(array $sanitized, string $parentRunId): ForkCompactionResult
    {
        $result = $this->virtualCompactionOrchestrator->compactForRun($parentRunId, $sanitized, force: false);

        return new ForkCompactionResult(
            messages: $result->compactedMessages,
            compacted: $result->compacted,
            summaryText: $result->summaryText,
            summarizedCount: $result->summarizedCount,
        );
    }
}
