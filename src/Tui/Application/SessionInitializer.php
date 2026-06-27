<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
        private TuiRuntimeEventApplier $eventApplier,
    ) {
    }

    /**
     * Initialize a fresh draft session state without creating a DB row.
     *
     * The session is created lazily by SubmitListener when the user
     * submits their first message — no orphan DB/session records are
     * created if the user types /new and never sends a message.
     *
     * @param StartRunRequest|null $request Optional pre-configured request
     */
    public function initializeDraft(?StartRunRequest $request = null): TuiSessionState
    {
        $state = new TuiSessionState('', false);

        if (null !== $request) {
            $state->request = $request;
        }

        return $state;
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

        // Fresh session or draft: no file persistence — events.jsonl is the canonical record.
        // A welcome block is returned for in-memory display only.
        // For draft sessions (sessionId === ''), use a placeholder runId.
        $runId = '' !== $state->sessionId ? $state->sessionId : '(new draft)';

        return [$this->blockFactory->system(
            runId: $runId,
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
        } catch (\Throwable $e) {
            // Intentional local degradation: events.jsonl is unreadable.
            // Log the error with sanitised correlation fields so operators
            // can diagnose without seeing raw prompts or tool output.
            $this->logger->warning('Session transcript replay: events.jsonl unreadable, falling back to system block', [
                'component' => 'SessionInitializer',
                'event_type' => 'replay_events_unreadable',
                'session_id' => $runId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

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

        $maxSourceSeq = 0;
        $maxMappedSeq = 0;

        foreach ($runEvents as $runEvent) {
            // Track the highest source seq so lastSeq is never stale even
            // when every event maps to null (e.g. only internal bookkeeping).
            if ($runEvent->seq > $maxSourceSeq) {
                $maxSourceSeq = $runEvent->seq;
            }

            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);

            if (null === $runtimeEvent) {
                continue; // Dropped/ignored event types
            }

            if ($runtimeEvent->seq > $maxMappedSeq) {
                $maxMappedSeq = $runtimeEvent->seq;
            }

            $this->eventApplier->apply($state, $runtimeEvent, replayMode: true);
        }

        // Set lastSeq so the live poller does not re-process replayed events.
        // Use the max of mapped event seqs; fall back to max source event seq
        // when every source event was dropped/ignored by the mapper.
        $state->lastSeq = max($maxMappedSeq, $maxSourceSeq);

        if ($state->isShellRun = $this->inferShellOnlySessionFromCanonicalEvents($runEvents)) {
            // Restored for SubmitListener: next normal prompt must start() not follow_up.
        }

        // Passive resume: historical mid-turn activity must not imply a live run.
        // Attach does not continue AgentCore; stale Running/Cancelling/Compacting
        // would route new input as steer and show Working without a runtime process.
        // Compacting is not isActive() but still sets isCompacting and activity.
        if ($state->activity->isActive() || RunActivityStateEnum::Compacting === $state->activity) {
            $state->activity = RunActivityStateEnum::Idle;
            $state->isCompacting = false;
        }

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

    /**
     * Detect first-input shell-only sessions from canonical events (no run_started / LLM steps).
     *
     * @param list<RunEvent> $runEvents
     */
    private function inferShellOnlySessionFromCanonicalEvents(array $runEvents): bool
    {
        $hasBashTool = false;
        $hasLlmConversation = false;
        $terminalCompleted = false;

        foreach ($runEvents as $runEvent) {
            $type = $runEvent->type;
            $payload = $runEvent->payload;

            if ('run_started' === $type || 'llm_step_completed' === $type) {
                $hasLlmConversation = true;
            }

            if ('tool_execution_start' === $type && 'bash' === (string) ($payload['tool_name'] ?? '')) {
                $hasBashTool = true;
            }

            if ('agent_end' === $type && 'completed' === (string) ($payload['reason'] ?? '')) {
                $terminalCompleted = true;
            }
        }

        return $hasBashTool && !$hasLlmConversation && $terminalCompleted;
    }
}
