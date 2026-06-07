<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;

/**
 * Initializes session state for the interactive TUI.
 *
 * Handles:
 *   - New session creation (generates ID, no file persistence)
 *   - Session resumption (replays transcript from canonical events.jsonl)
 *   - Initial run start (if a pre-configured request is provided)
 *
 * Extracted from InteractiveMode::run() so the session lifecycle
 * is independently testable and the run() method stays lean.
 *
 * On resume, transcript blocks are rebuilt from events.jsonl through
 * RuntimeEventMapper + TranscriptProjector instead of loading from a
 * separate transcript.jsonl file. This ensures the TUI transcript is
 * always a derived projection of the canonical event stream.
 */
final readonly class SessionInitializer
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private SessionRunEventStore $eventStore,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $projector,
        private TranscriptBlockFactory $blockFactory,
    ) {
    }

    /**
     * Initialize session state and return ready-to-use TuiSessionState.
     *
     * @param string               $sessionId Existing session ID to resume; empty = new session
     * @param StartRunRequest|null $request   Optional pre-configured start request
     */
    public function initialize(
        string $sessionId = '',
        ?StartRunRequest $request = null,
    ): TuiSessionState {
        $resuming = '' !== $sessionId && $this->sessionStore->exists($sessionId);

        if (!$resuming) {
            $promptText = null !== $request ? $request->prompt : '';
            $sessionId = $this->sessionStore->createSession($promptText);
        }

        $state = new TuiSessionState($sessionId, $resuming);

        // Inject session ID as the run ID when starting with an initial prompt
        if (null !== $request && '' !== $request->prompt && '' === $request->runId) {
            $state->request = new StartRunRequest(
                prompt: $request->prompt,
                runId: $sessionId,
                cwd: $request->cwd,
                options: $request->options,
                model: $request->model,
                reasoning: $request->reasoning,
            );
        } elseif (null !== $request) {
            $state->request = $request;
        }

        return $state;
    }

    /**
     * Build the initial transcript blocks for the session.
     *
     * On resume, replays canonical events.jsonl through RuntimeEventMapper
     * and TranscriptProjector to reconstruct the transcript block history.
     * On fresh session, returns a welcome block.
     *
     * Returns plain projection blocks; theme colors/prefixes are applied
     * at display time by ChatScreen/TranscriptBlockWidget.
     *
     * @return list<TranscriptBlock>
     */
    public function buildInitialTranscript(TuiSessionState $state): array
    {
        if ($state->resuming) {
            return $this->replayFromEvents($state);
        }

        // New session: no file persistence — events.jsonl is the canonical record.
        // A welcome block is returned for in-memory display only.
        return [$this->blockFactory->system(
            runId: $state->sessionId,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        )];
    }

    /**
     * Replay canonical events.jsonl through the projector to rebuild transcript.
     *
     * @return list<TranscriptBlock>
     */
    private function replayFromEvents(TuiSessionState $state): array
    {
        // Reset projector to clear any stale state from previous runs.
        $this->projector->reset();

        // Use the session ID as the run ID (they are the same in Hatfield).
        $runId = $state->sessionId;

        try {
            $runEvents = $this->eventStore->allFor($runId);
        } catch (\Throwable) {
            // If events.jsonl is missing or corrupt, show a minimal fallback.
            return [$this->blockFactory->system(
                runId: $runId,
                text: 'Session '.$runId.' — could not load events.',
                seq: 1,
            )];
        }

        if ([] === $runEvents) {
            return [$this->blockFactory->system(
                runId: $runId,
                text: 'Session '.$runId.' — no messages yet.',
                seq: 1,
            )];
        }

        $maxSeq = 0;

        foreach ($runEvents as $runEvent) {
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);

            if (null === $runtimeEvent) {
                continue; // Dropped/ignored event types
            }

            if ($runtimeEvent->seq > $maxSeq) {
                $maxSeq = $runtimeEvent->seq;
            }

            $this->projector->accept($runtimeEvent->toArray());
        }

        // Set lastSeq so the live poller does not re-process replayed events.
        $state->lastSeq = $maxSeq;

        $blocks = $this->projector->blocks();

        if ([] === $blocks) {
            return [$this->blockFactory->system(
                runId: $runId,
                text: 'Session '.$runId.' — no messages yet.',
                seq: 1,
            )];
        }

        return $blocks;
    }
}
