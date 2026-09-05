<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;

/**
 * Full-stream child run replay projection using an isolated TranscriptProjector instance.
 *
 * Does not apply retained-history filtering (unlike SessionTranscriptProvider).
 */
final readonly class ChildRunTranscriptSnapshotProvider implements ChildRunTranscriptSnapshotProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $transcriptProjector,
        private ToolQuestionStoreInterface $toolQuestionStore,
    ) {
    }

    public function snapshot(string $runId): ChildRunTranscriptSnapshotDTO
    {
        $runEvents = $this->eventStore->allFor($runId);

        $replayEvents = [];
        $maxSeq = 0;

        foreach ($runEvents as $runEvent) {
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null === $runtimeEvent) {
                continue;
            }

            $replayEvents[] = $runtimeEvent;

            if ($runtimeEvent->seq > 0 && $runtimeEvent->seq > $maxSeq) {
                $maxSeq = $runtimeEvent->seq;
            }
        }

        $this->transcriptProjector->reset();

        foreach ($replayEvents as $runtimeEvent) {
            $this->transcriptProjector->accept($runtimeEvent);
        }

        $blocks = $this->transcriptProjector->blocks();
        $this->transcriptProjector->reset();

        return new ChildRunTranscriptSnapshotDTO(
            $blocks,
            [...$replayEvents, ...$this->pendingToolQuestionEvents($runId)],
            $maxSeq,
        );
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
