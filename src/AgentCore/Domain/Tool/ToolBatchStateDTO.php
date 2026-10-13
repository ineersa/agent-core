<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Runtime and SQL scheduling state for one (run, turn, step).
 *
 * The active SQL batch owns typed calls and collected sibling results alongside
 * membership, FIFO queue, in-flight work, and human-input waits.
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
        #[SerializedName('call_data')]
        #[Assert\Valid]
        public array $calls,
        public array $pendingQueue,
        public array $inFlight,
        #[SerializedName('result_data')]
        public array $results,
        public bool $finalized,
        #[Assert\GreaterThanOrEqual(1)]
        public int $maxParallelism,
        public array $awaitingHumanInput = [],
    ) {
    }
}
