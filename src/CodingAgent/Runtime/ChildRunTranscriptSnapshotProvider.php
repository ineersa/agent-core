<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\ResumeActivityReducer;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\RunState\RunStateStoreInterface;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Full-stream child run projection using an isolated TranscriptProjector instance.
 *
 * First enter cold-streams rangeFor once and publishes a shared disposable snapshot.
 * Later enters reuse that snapshot, or advance only the physically unread suffix via
 * {@see ChildAwareEventStore::readAfterSeq()}. Freshness is maintained RunState.lastSeq
 * under the transition lock — not an archive tip probe or TTL.
 */
final readonly class ChildRunTranscriptSnapshotProvider implements ChildRunTranscriptSnapshotProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $transcriptProjector,
        private ToolQuestionStoreInterface $toolQuestionStore,
        private ChildRunTranscriptSnapshotStoreInterface $snapshotStore,
        private RunStateStoreInterface $runStateStore,
        private ChildRunPhysicalSuffixReaderInterface $physicalSuffixReader,
        private RunLockManager $runLockManager,
        private LoggerInterface $logger,
    ) {
    }

    public function snapshot(string $runId): ChildRunTranscriptSnapshotDTO
    {
        return $this->runLockManager->synchronized($runId, function () use ($runId): ChildRunTranscriptSnapshotDTO {
            $cached = $this->snapshotStore->find($runId);
            if (null === $cached) {
                $built = $this->buildFromArchive($runId);
                $this->snapshotStore->remember($runId, $built);

                return $this->withFreshPendingToolQuestions($runId, $built);
            }

            $authoritativeSeq = $this->authoritativeMaxSeq($runId);
            if ($cached->maxSeq === $authoritativeSeq) {
                return $this->withFreshPendingToolQuestions($runId, $cached);
            }

            if ($cached->maxSeq > $authoritativeSeq) {
                throw new \RuntimeException(\sprintf('Child transcript snapshot for run %s is ahead of shared state (snapshot seq %d, state seq %d); recovery required.', $runId, $cached->maxSeq, $authoritativeSeq));
            }

            $advanced = $this->advanceFromCached($runId, $cached, $authoritativeSeq);
            $this->snapshotStore->remember($runId, $advanced);

            return $this->withFreshPendingToolQuestions($runId, $advanced);
        });
    }

    private function authoritativeMaxSeq(string $runId): int
    {
        try {
            $state = $this->runStateStore->find($runId);
            if (null === $state) {
                throw new \RuntimeException(\sprintf('Run state projection missing for run %s; initialize via startup/new-run/recovery before ordinary lookups.', $runId));
            }

            if (!$this->runStateStore->isReady($runId)) {
                throw new \RuntimeException(\sprintf('Shared run state for child run %s is not ready; recovery required before snapshot reuse.', $runId));
            }

            return $state->lastSeq;
        } catch (\RuntimeException $exception) {
            $this->logger->warning('child_transcript_snapshot.shared_state_unavailable', [
                'run_id' => $runId,
                'component' => 'child_transcript_snapshot',
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function advanceFromCached(
        string $runId,
        ChildRunTranscriptSnapshotDTO $cached,
        int $authoritativeSeq,
    ): ChildRunTranscriptSnapshotDTO {
        $suffix = $this->physicalSuffixReader->readAfterSeq($runId, $cached->maxSeq);
        if ([] === $suffix) {
            throw new \RuntimeException(\sprintf('Child transcript snapshot for run %s needs suffix after seq %d to reach shared seq %d, but no physical events were available.', $runId, $cached->maxSeq, $authoritativeSeq));
        }

        $this->transcriptProjector->reset();
        $this->transcriptProjector->replaceProjectedBlocks($cached->transcriptBlocks);

        $activity = new ResumeActivityReducer(
            activity: $cached->resume->activity,
            isCompacting: $cached->resume->isCompacting,
        );
        $pendingHuman = [];
        foreach ($cached->pendingHumanInputEvents as $event) {
            $questionId = $event->payload['question_id'] ?? '';
            if (\is_string($questionId) && '' !== $questionId) {
                $pendingHuman[$questionId] = $event;
            }
        }
        $queuedUserMessages = $cached->resume->queuedUserMessages;
        $llmRetryWorkingMessage = $cached->resume->llmRetryWorkingMessage;
        $maxSeq = $cached->maxSeq;

        foreach ($suffix as $runEvent) {
            if ($runEvent->runId !== $runId) {
                throw new \RuntimeException(\sprintf('Physical suffix for child run %s contained event for run %s.', $runId, $runEvent->runId));
            }
            if ($runEvent->seq <= $maxSeq) {
                throw new \RuntimeException(\sprintf('Physical suffix for child run %s is not strictly after cursor %d.', $runId, $cached->maxSeq));
            }

            $maxSeq = max($maxSeq, $runEvent->seq);
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null === $runtimeEvent) {
                continue;
            }

            $this->transcriptProjector->accept($runtimeEvent);
            $activity->apply($runtimeEvent);
            $this->accumulateResumeFields($runtimeEvent, $queuedUserMessages, $llmRetryWorkingMessage);
            $this->accumulatePendingHuman($runtimeEvent, $pendingHuman);
        }

        if ($maxSeq !== $authoritativeSeq) {
            throw new \RuntimeException(\sprintf('Child transcript suffix for run %s reached seq %d but shared state is at seq %d; recovery required.', $runId, $maxSeq, $authoritativeSeq));
        }

        $blocks = $this->transcriptProjector->blocks();
        $this->transcriptProjector->reset();

        return new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: $blocks,
            resume: new SessionResumeProjectionDTO(
                queuedUserMessages: $queuedUserMessages,
                llmRetryWorkingMessage: $llmRetryWorkingMessage,
                activity: $activity->activity(),
                isCompacting: $activity->isCompacting(),
            ),
            pendingHumanInputEvents: array_values($pendingHuman),
            // Local tool questions are refreshed once on return so enter/reuse
            // share a single store read.
            pendingToolQuestionEvents: [],
            maxSeq: $maxSeq,
        );
    }

    private function buildFromArchive(string $runId): ChildRunTranscriptSnapshotDTO
    {
        $this->transcriptProjector->reset();
        $activity = new ResumeActivityReducer();
        $pendingHuman = [];
        $maxSeq = 0;
        $queuedUserMessages = [];
        $llmRetryWorkingMessage = null;

        foreach ($this->eventStore->rangeFor($runId, 1, \PHP_INT_MAX) as $runEvent) {
            // The live cursor includes canonical events without a UI mapping.
            $maxSeq = max($maxSeq, $runEvent->seq);
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null === $runtimeEvent) {
                continue;
            }

            $this->transcriptProjector->accept($runtimeEvent);
            $activity->apply($runtimeEvent);
            $this->accumulateResumeFields($runtimeEvent, $queuedUserMessages, $llmRetryWorkingMessage);
            $this->accumulatePendingHuman($runtimeEvent, $pendingHuman);
        }

        $blocks = $this->transcriptProjector->blocks();
        // The shared projector must not retain the child's blocks after the snapshot returns.
        $this->transcriptProjector->reset();

        return new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: $blocks,
            resume: new SessionResumeProjectionDTO(
                queuedUserMessages: $queuedUserMessages,
                llmRetryWorkingMessage: $llmRetryWorkingMessage,
                activity: $activity->activity(),
                isCompacting: $activity->isCompacting(),
            ),
            pendingHumanInputEvents: array_values($pendingHuman),
            // Local tool questions are refreshed once on return so enter/reuse
            // share a single store read.
            pendingToolQuestionEvents: [],
            maxSeq: $maxSeq,
        );
    }

    private function withFreshPendingToolQuestions(string $runId, ChildRunTranscriptSnapshotDTO $snapshot): ChildRunTranscriptSnapshotDTO
    {
        return new ChildRunTranscriptSnapshotDTO(
            transcriptBlocks: $snapshot->transcriptBlocks,
            resume: $snapshot->resume,
            pendingHumanInputEvents: $snapshot->pendingHumanInputEvents,
            pendingToolQuestionEvents: $this->pendingToolQuestionEvents($runId),
            maxSeq: $snapshot->maxSeq,
        );
    }

    /**
     * @param array<string, string> $queuedUserMessages
     */
    private function accumulateResumeFields(
        RuntimeEvent $event,
        array &$queuedUserMessages,
        ?string &$llmRetryWorkingMessage,
    ): void {
        if (RuntimeEventTypeEnum::UserMessageQueued->value === $event->type) {
            $messageId = $event->payload['message_id'] ?? null;
            $text = $event->payload['text'] ?? null;
            if (\is_string($messageId) && '' !== $messageId && \is_string($text)) {
                $queuedUserMessages[$messageId] = $text;
            }

            return;
        }

        if (RuntimeEventTypeEnum::UserMessageSubmitted->value === $event->type) {
            $messageId = $event->payload['message_id'] ?? null;
            if (\is_string($messageId)) {
                unset($queuedUserMessages[$messageId]);
            }

            return;
        }

        if (RuntimeEventTypeEnum::LlmRequestRetrying->value === $event->type) {
            $attempt = \is_int($event->payload['attempt'] ?? null) ? $event->payload['attempt'] : 0;
            $maxAttempts = \is_int($event->payload['max_attempts'] ?? null) ? $event->payload['max_attempts'] : 0;
            $llmRetryWorkingMessage = \sprintf('Retrying LLM request (%d/%d)...', $attempt, $maxAttempts);
        }
    }

    /**
     * @param array<string, RuntimeEvent> $pendingHuman
     */
    private function accumulatePendingHuman(RuntimeEvent $runtimeEvent, array &$pendingHuman): void
    {
        $questionId = $runtimeEvent->payload['question_id'] ?? '';
        if (RuntimeEventTypeEnum::HumanInputRequested->value === $runtimeEvent->type && \is_string($questionId) && '' !== $questionId) {
            $pendingHuman[$questionId] = $runtimeEvent;
        } elseif (
            (RuntimeEventTypeEnum::HumanInputAnswered->value === $runtimeEvent->type
                || RuntimeEventTypeEnum::HumanInputRejected->value === $runtimeEvent->type)
            && \is_string($questionId)
        ) {
            unset($pendingHuman[$questionId]);
        }
    }

    /**
     * @return list<RuntimeEvent>
     */
    private function pendingToolQuestionEvents(string $runId): array
    {
        $events = [];
        foreach ($this->toolQuestionStore->findPendingQuestionsForRun($runId) as $question) {
            $events[] = new RuntimeEvent(
                type: RuntimeEventTypeEnum::ToolQuestionRequested->value,
                runId: $question->runId,
                seq: 0,
                payload: [
                    'request_id' => $question->requestId,
                    'run_id' => $question->runId,
                    'tool_call_id' => $question->toolCallId,
                    'tool_name' => $question->toolName,
                    'pid' => $question->pid,
                    'log_path' => $question->logPath,
                    'command_preview' => $question->commandPreview,
                    'prompt' => $question->prompt,
                    'kind' => $question->kind,
                    'schema' => $question->schema,
                    'transcript' => false,
                ],
            );
        }

        return $events;
    }
}
