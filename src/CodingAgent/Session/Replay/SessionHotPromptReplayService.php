<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Dto\ReplayIntegrity;
use Ineersa\AgentCore\Application\Dto\ResolvedReplayEvents;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\PromptStateStoreInterface;
use Ineersa\AgentCore\Contract\Replay\HotPromptIntegrityVerifierInterface;
use Ineersa\AgentCore\Contract\Replay\HotPromptStateRebuilderInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\PromptState;
use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class SessionHotPromptReplayService implements HotPromptStateRebuilderInterface, HotPromptIntegrityVerifierInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private PromptStateStoreInterface $promptStateStore,
        private PromptStateReplayService $promptStateReplayService,
        private ReplayEventPreparer $replayEventPreparer,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    public function rebuildHotPromptState(RunState $state): PromptState
    {
        $rebuild = function () use ($state): PromptState {
            $messages = array_map(static fn (AgentMessage $message): array => $message->toArray(), $state->messages);
            $promptState = new PromptState(
                runId: $state->runId,
                source: 'run_state',
                eventCount: 0,
                lastSeq: $state->lastSeq,
                missingSequences: [],
                isContiguous: true,
                tokenEstimate: $this->promptStateReplayService->estimateTokens($messages),
                messages: $messages,
            );

            $this->promptStateStore->save($state->runId, $promptState);
            $this->metrics?->incrementReplayRebuildCount('run_state');

            return $promptState;
        };

        if (null === $this->tracer) {
            return $rebuild();
        }

        return $this->tracer->inSpan('replay.rebuild_hot_prompt_state', [
            'run_id' => $state->runId,
        ], $rebuild);
    }

    /**
     * Validates event sequence integrity and identifies missing sequences for a run.
     */
    public function verifyIntegrity(string $runId): ReplayIntegrity
    {
        return $this->integrityFromResolvedReplayEvents($runId, $this->eventsForReplay($runId));
    }

    /**
     * Fetches and sorts events required for replaying a specific run.
     */
    private function eventsForReplay(string $runId): ResolvedReplayEvents
    {
        $events = $this->eventStore->allFor($runId);

        return new ResolvedReplayEvents(
            events: $this->replayEventPreparer->sortBySequence($events),
            source: 'canonical_events',
        );
    }

    private function integrityFromResolvedReplayEvents(string $runId, ResolvedReplayEvents $resolvedReplayEvents): ReplayIntegrity
    {
        $missingSequences = $this->replayEventPreparer->missingSequences($resolvedReplayEvents->events);

        return new ReplayIntegrity(
            runId: $runId,
            source: $resolvedReplayEvents->source,
            eventCount: \count($resolvedReplayEvents->events),
            lastSeq: [] === $resolvedReplayEvents->events
                ? 0
                : max(array_map(static fn (RunEvent $event): int => $event->seq, $resolvedReplayEvents->events)),
            missingSequences: $missingSequences,
            isContiguous: [] === $missingSequences,
        );
    }
}
