<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Runtime and persisted tool-batch coordination state for one (run, turn, step).
 *
 * In-process maps hold typed bus messages. Session snapshots serialize this DTO
 * via Serializer group tool_batch_snapshot (including nested bus identity fields).
 */
final class ToolBatchStateDTO
{
    public const SNAPSHOT_GROUP = 'tool_batch_snapshot';

    /**
     * @param array<string, int>             $expectedOrder
     * @param array<string, ExecuteToolCall> $calls
     * @param list<string>                   $pendingQueue
     * @param array<string, true>            $inFlight
     * @param array<string, ToolCallResult>  $results
     * @param array<string, string>          $awaitingHumanInput tool_call_id => request question_id
     */
    public function __construct(
        #[Groups([self::SNAPSHOT_GROUP])]
        public array $expectedOrder,
        #[SerializedName('call_data')]
        #[Groups([self::SNAPSHOT_GROUP])]
        #[Assert\Valid]
        public array $calls,
        #[Groups([self::SNAPSHOT_GROUP])]
        public array $pendingQueue,
        #[Groups([self::SNAPSHOT_GROUP])]
        public array $inFlight,
        #[SerializedName('result_data')]
        #[Groups([self::SNAPSHOT_GROUP])]
        public array $results,
        #[Groups([self::SNAPSHOT_GROUP])]
        public bool $finalized,
        #[Groups([self::SNAPSHOT_GROUP])]
        #[Assert\GreaterThanOrEqual(1)]
        public int $maxParallelism,
        #[Groups([self::SNAPSHOT_GROUP])]
        public array $awaitingHumanInput = [],
    ) {
    }
}
