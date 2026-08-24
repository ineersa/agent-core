<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Polls a selected child run id and projects readonly live transcript blocks.
 *
 * Canonical child history is replayed once on live-view entry via {@see replaySnapshot()};
 * {@see poll()} consumes only live {@see AgentSessionClient::events()} for the child run id.
 *
 * Optional HITL callbacks mirror RuntimeEventPoller so child human_input.requested
 * and tool_question.requested events can drive the shared QuestionCoordinator.
 */
final class SubagentLiveChildViewPoller
{
    private const float POLL_INTERVAL = 0.05;

    private readonly TuiRuntimeEventApplier $eventApplier;

    /** @var list<RuntimeEvent> */
    private array $pendingEvents = [];

    public function __construct(
        private readonly TranscriptProjectorInterface $projector,
        private readonly LoggerInterface $logger,
        DenormalizerInterface $denormalizer,
    ) {
        $this->eventApplier = new TuiRuntimeEventApplier($this->projector, $denormalizer);
    }

    public function resetProjection(): void
    {
        $this->projector->reset();
    }

    /**
     * One-time replay of a canonical child snapshot into live view state and the child projector.
     *
     * Call only while {@see SubagentLiveViewState::$active} is true (picker sets this before replay).
     * Transient seq=0 replay events do not advance {@see SubagentLiveViewState::$childLastSeq}.
     *
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     *
     * @return list<TranscriptBlock>
     */
    public function replaySnapshot(
        SubagentLiveViewState $live,
        ChildRunTranscriptSnapshotDTO $snapshot,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
    ): array {
        if (!$live->active || null === $live->selected) {
            return $live->childTranscript;
        }

        $this->projector->reset();

        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;

        $callbacks = $this->makeCallbacks($onHumanInputRequested, $onToolQuestionRequested, $onToolTerminal);

        foreach ($snapshot->replayEvents as $event) {
            $this->eventApplier->apply($scratch, $event, replayMode: true);
            $callbacks->dispatch($event, $live->selected->agentRunId);
        }

        $live->childActivity = $scratch->activity;
        $live->childQueuedUserMessages = $scratch->queuedUserMessages;
        $live->childLastSeq = $snapshot->maxSeq;
        $live->childReplayEvents = $snapshot->replayEvents;
        $projected = $this->projector->blocks();
        $live->childTranscript = [] !== $projected
            ? $projected
            : $snapshot->transcriptBlocks;
        $live->persistCurrentChildCache();

        return $live->childTranscript;
    }

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     *
     * @return list<TranscriptBlock>|null
     */
    public function poll(
        SubagentLiveViewState $live,
        AgentSessionClient $client,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
    ): ?array {
        if (!$live->active || null === $live->selected) {
            return null;
        }

        $now = microtime(true);
        if (($now - $live->childLastPoll) < self::POLL_INTERVAL) {
            return null;
        }
        $live->childLastPoll = $now;

        if ([] !== $this->pendingEvents && $this->pendingEvents[0]->runId !== $live->selected->agentRunId) {
            $this->pendingEvents = [];
        }

        $events = [] !== $this->pendingEvents
            ? $this->pendingEvents
            : RuntimeEventCallbacks::eventList($client, $live->selected->agentRunId);
        if ([] === $events) {
            return null;
        }

        $changed = false;
        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;

        $callbacks = $this->makeCallbacks($onHumanInputRequested, $onToolQuestionRequested, $onToolTerminal);

        foreach ($events as $index => $event) {
            $seq = $event->seq;
            if (0 !== $seq && $seq <= $live->childLastSeq) {
                continue;
            }

            try {
                $this->eventApplier->apply($scratch, $event);
                $callbacks->dispatch($event, $live->selected->agentRunId);
            } catch (\Throwable $exception) {
                $this->pendingEvents = \array_slice($events, $index);

                throw $exception;
            }

            if (0 !== $seq) {
                $live->childLastSeq = $seq;
            }
            $live->childReplayEvents[] = $event;
            $changed = true;
        }
        $this->pendingEvents = [];

        if ($changed) {
            $live->childActivity = $scratch->activity;
            $live->childQueuedUserMessages = $scratch->queuedUserMessages;
        }

        if (!$changed) {
            return null;
        }

        $live->childTranscript = $this->projector->blocks();
        $live->persistCurrentChildCache();

        return $live->childTranscript;
    }

    /**
     * @param ?callable(RuntimeEvent): void $onHumanInputRequested
     * @param ?callable(RuntimeEvent): void $onToolQuestionRequested
     * @param ?callable(RuntimeEvent): void $onToolTerminal
     */
    private function makeCallbacks(
        ?callable $onHumanInputRequested,
        ?callable $onToolQuestionRequested,
        ?callable $onToolTerminal,
    ): RuntimeEventCallbacks {
        return new RuntimeEventCallbacks(
            $this->logger,
            'SubagentLiveChildViewPoller event callback failed',
            'tui.subagent_live_child_poller',
            'subagent_live_child_poller.callback_failed',
            $onHumanInputRequested,
            $onToolQuestionRequested,
            $onToolTerminal,
        );
    }
}
