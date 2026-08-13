<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;

/**
 * Runtime and persisted tool-batch coordination state for one (run, turn, step).
 *
 * In-process maps already hold typed bus messages. Snapshot encode/decode of the
 * fixed nested rows lives in {@see ToolBatchStateCodec}.
 */
final class ToolBatchStateDTO
{
    /**
     * @param array<string, int>             $expectedOrder
     * @param array<string, ExecuteToolCall> $calls
     * @param list<string>                   $pendingQueue
     * @param array<string, true>            $inFlight
     * @param array<string, ToolCallResult>  $results
     * @param array<string, string>          $awaitingHumanInput tool_call_id => request question_id
     */
    public function __construct(
        public array $expectedOrder,
        public array $calls,
        public array $pendingQueue,
        public array $inFlight,
        public array $results,
        public bool $finalized,
        public int $maxParallelism,
        public array $awaitingHumanInput = [],
    ) {
    }
}
