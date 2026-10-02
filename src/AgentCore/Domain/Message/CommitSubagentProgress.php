<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Frozen progress for one durable child batch, consumed by its parent owner. */
final readonly class CommitSubagentProgress extends AbstractAgentBusMessage
{
    /** @param array<string, mixed> $progress */
    public function __construct(
        string $runId,
        int $turnNo,
        public string $lifecycleId,
        public string $toolCallId,
        public int $orderIndex,
        public int $revision,
        public array $progress,
        public ?string $interruptionKind = null,
    ) {
        parent::__construct($runId, $turnNo, $lifecycleId, 1, $lifecycleId.':progress:'.($interruptionKind ?? (string) $revision));
    }
}
