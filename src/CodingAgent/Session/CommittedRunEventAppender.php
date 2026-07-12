<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Psr\Log\LoggerInterface;

/**
 * Appends parent-session events through a committed store and syncs parent RunState.lastSeq.
 */
final readonly class CommittedRunEventAppender
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RunStoreInterface $runStore,
        private LoggerInterface $logger,
    ) {
    }

    public function append(RunEvent $event): RunEvent
    {
        $persisted = $this->eventStore->append($event);
        $this->syncParentLastSeq($persisted->runId, $persisted->seq);

        return $persisted;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function appendMany(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $persisted = $this->eventStore->appendMany($events);
        $last = $persisted[array_key_last($persisted)];
        $this->syncParentLastSeq($last->runId, $last->seq);

        return $persisted;
    }

    private function syncParentLastSeq(string $runId, int $seq): void
    {
        $state = $this->runStore->get($runId);
        if (null === $state || $state->lastSeq >= $seq) {
            return;
        }

        $nextState = new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $seq,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
        );

        if (!$this->runStore->compareAndSwap($nextState, $state->version)) {
            $this->logger->debug('sequenced_event_append.last_seq_cas_skipped', [
                'run_id' => $runId,
                'target_seq' => $seq,
                'component' => 'session.event_store',
                'event_type' => 'sequenced_event_append.last_seq_cas_skipped',
            ]);
        }
    }
}
