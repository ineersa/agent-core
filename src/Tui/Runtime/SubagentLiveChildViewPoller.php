<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;

/**
 * Polls a selected child run id and projects readonly live transcript blocks.
 *
 * Optional HITL callbacks mirror RuntimeEventPoller so child human_input.requested
 * and tool_question.requested events can drive the shared QuestionCoordinator.
 */
final class SubagentLiveChildViewPoller
{
    private const float POLL_INTERVAL = 0.05;

    private readonly TuiRuntimeEventApplier $eventApplier;

    public function __construct(
        private readonly TranscriptProjectorInterface $projector,
        private readonly LoggerInterface $logger,
    ) {
        $this->eventApplier = new TuiRuntimeEventApplier($this->projector);
    }

    public function resetProjection(): void
    {
        $this->projector->reset();
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

        $events = $this->runtimeEvents($client, $live->selected->agentRunId);
        if ([] === $events) {
            return null;
        }

        $changed = false;
        $scratch = new TuiSessionState($live->selected->agentRunId);
        $scratch->activity = $live->childActivity;
        $scratch->queuedUserMessages = $live->childQueuedUserMessages;

        foreach ($events as $event) {
            $seq = $event->seq;
            if (0 !== $seq && $seq <= $live->childLastSeq) {
                continue;
            }
            if (0 !== $seq) {
                $live->childLastSeq = $seq;
            }

            $this->eventApplier->apply($scratch, $event);
            $changed = true;

            if (null !== $onHumanInputRequested && RuntimeEventTypeEnum::HumanInputRequested->value === $event->type) {
                $this->invokeEventCallback($onHumanInputRequested, $event, $live->selected->agentRunId, 'onHumanInputRequested');
            }

            if (null !== $onToolQuestionRequested && RuntimeEventTypeEnum::ToolQuestionRequested->value === $event->type) {
                $this->invokeEventCallback($onToolQuestionRequested, $event, $live->selected->agentRunId, 'onToolQuestionRequested');
            }

            if (null !== $onToolTerminal && (
                RuntimeEventTypeEnum::ToolExecutionCompleted->value === $event->type
                || RuntimeEventTypeEnum::ToolExecutionFailed->value === $event->type
                || RuntimeEventTypeEnum::ToolExecutionCancelled->value === $event->type
            )) {
                $this->invokeEventCallback($onToolTerminal, $event, $live->selected->agentRunId, 'onToolTerminal');
            }
        }

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
     * @param callable(RuntimeEvent): void $callback
     */
    private function invokeEventCallback(callable $callback, RuntimeEvent $runtimeEvent, string $childRunId, string $callbackName): void
    {
        try {
            $callback($runtimeEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('SubagentLiveChildViewPoller event callback failed', [
                'component' => 'tui.subagent_live_child_poller',
                'event_type' => 'subagent_live_child_poller.callback_failed',
                'run_id' => $childRunId,
                'callback' => $callbackName,
                'runtime_event_type' => $runtimeEvent->type,
                'seq' => $runtimeEvent->seq,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }

    /** @return list<RuntimeEvent> */
    private function runtimeEvents(AgentSessionClient $client, string $runId): array
    {
        $events = $client->events($runId);
        if ($events instanceof \Traversable) {
            return iterator_to_array($events, false);
        }

        return $events;
    }
}
