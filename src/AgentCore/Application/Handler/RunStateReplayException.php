<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;

/**
 * Typed corruption signal when duplicate sequence numbers are detected in persisted run event history.
 *
 * Raised by replay rebuilders ({@see RunStateRebuilderInterface} implementations such as session replay)
 * and by history-select preflight ({@see \Ineersa\CodingAgent\Session\History\HistorySelectionService::selectPrompt()})
 * before appending a HistoryPositionSet event. Use {@see RunStateDuplicateSequenceReplayException}
 * to distinguish this case from other failures.
 *
 * Sequence gaps (for example after cursor allocation without JSONL append) are tolerated and do not throw.
 * Incompatible or corrupt JSONL payload shapes are handled separately (skipped lines, denormalization failures, or other exceptions).
 */
class RunStateReplayException extends \RuntimeException
{
    public function __construct(
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
