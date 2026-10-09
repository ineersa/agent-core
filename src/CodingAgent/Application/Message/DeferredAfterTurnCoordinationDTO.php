<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

use Ineersa\AgentCore\Contract\CanonicalSequenceBoundActionInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;

final readonly class DeferredAfterTurnCoordinationDTO implements CanonicalSequenceBoundActionInterface
{
    public function __construct(public string $runId, public int $predecessorSequence, public ObserveDeferredSubagentBatchChildTurnMessage|InterruptDeferredSubagentBatchMessage $message)
    {
    }

    public function bindCanonicalSequences(array $sequences): object
    {
        if (!$this->message instanceof ObserveDeferredSubagentBatchChildTurnMessage) {
            return $this;
        }
        if (\count($sequences) !== \count($this->message->committedEvents)) {
            throw new \RuntimeException('Child observation differs from the staged event batch.');
        }
        $events = [];
        foreach ($this->message->committedEvents as $index => $event) {
            $events[] = new AfterTurnCommitEventSummary($sequences[$index], $event->type, $event->payload, $event->turnNo, $event->createdAt);
        }
        $message = $this->message;

        return new self($this->runId, $this->predecessorSequence, new ObserveDeferredSubagentBatchChildTurnMessage($message->batchLifecycleId, $message->batchIndex, $message->childRunId, $message->committedStatus, $message->turnNo, $events, $this->predecessorSequence));
    }
}
