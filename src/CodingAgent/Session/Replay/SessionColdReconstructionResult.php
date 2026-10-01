<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;

/**
 * Shared products of one cold archive reconstruction.
 */
final readonly class SessionColdReconstructionResult
{
    public function __construct(
        public string $runId,
        public int $lastSeq,
        public HistoryProjectionSnapshot $historySnapshot,
        public RunState $runState,
        public SessionTranscriptSnapshotDTO $transcript,
        public bool $isShellOnlySession,
        public ?string $terminalActivityReason,
        public bool $suppressTerminalActivityForInProgressCompaction,
    ) {
    }
}
