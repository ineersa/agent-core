<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;

/** A prepared coordination delta, excluding worker receipts and unchanged payloads. */
final readonly class FinalizeToolBatchDTO
{
    /**
     * @param list<string>          $pendingQueue
     * @param array<string, true>   $inFlight
     * @param array<string, string> $awaitingHumanInput
     */
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $stepId,
        public string $beforeHash,
        public string $afterHash,
        public array $pendingQueue,
        public array $inFlight,
        public array $awaitingHumanInput,
        public bool $finalized,
        public ?ToolCallResult $result = null,
        public ?string $revisedCallId = null,
        public ?ToolCallHumanInputAnswerDTO $answer = null,
    ) {
    }
}
