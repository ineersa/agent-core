<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\HistoryDTO;
use Ineersa\CodingAgent\Session\History\HistoryProjectionSnapshot;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryStreamBuilder;

/**
 * Single-pass retained-history and filter metadata for cold reconstruction.
 *
 * Event payloads are released after each {@see observe()} return. Filter decisions
 * for ordinary turn content use the provisional retained prefix; turn-seeding
 * commands stay deferred until {@see mapDeferredSeeds()} or unmatched suppression.
 */
final class HistoryReplayStreamPlanner
{
    private readonly HistoryStreamBuilder $historyBuilder;

    /** @var array<int, true> */
    private array $seenSequences = [];

    private int $maxSeq = 0;

    /** @var list<RunEvent> */
    private array $deferredSeeds = [];

    /** @var array<int, int> commandSeq → created turn after TurnAdvanced */
    private array $commandSeqToCreatedTurn = [];

    /** @var array<int, int> commandSeq → event turnNo for unmatched pending checks */
    private array $turnSeedingCommandTurns = [];

    /** @var array<int, int> turnNo → latest completion seq */
    private array $turnCompletionSeqByTurn = [];

    private bool $hasBashTool = false;

    private bool $hasLlmConversation = false;

    private bool $terminalCompleted = false;

    private ?string $latestAgentEndReason = null;

    private ?int $lastCompactionStartedSeq = null;

    private ?int $lastCompactionTerminalSeq = null;

    private ?int $lastAgentEndSeq = null;

    private bool $finished = false;

    private ?HistoryProjectionSnapshot $finishedSnapshot = null;

    public function __construct(HistoryProjector $projector)
    {
        $this->historyBuilder = $projector->createStreamBuilder();
    }

    public function observe(RunEvent $event): void
    {
        if ($this->finished) {
            throw new \LogicException('HistoryReplayStreamPlanner cannot accept events after finish.');
        }

        if (isset($this->seenSequences[$event->seq])) {
            throw new RunStateDuplicateSequenceReplayException(\sprintf('Cannot reconstruct run %s: duplicate sequence %d.', $event->runId, $event->seq));
        }
        if ($event->seq <= $this->maxSeq) {
            throw new \RuntimeException(\sprintf('Cannot reconstruct run %s: events are not in canonical sequence order.', $event->runId));
        }

        $this->seenSequences[$event->seq] = true;
        $this->maxSeq = $event->seq;
        $this->historyBuilder->apply($event);
        $this->observeResumeMetadata($event);
        $this->trackCompletionMarkers($event);
    }

    /**
     * @return list<RunEvent> seeds that map to $createdTurnNo, in archive order
     */
    public function mapDeferredSeeds(int $createdTurnNo): array
    {
        if ($createdTurnNo <= 0 || [] === $this->deferredSeeds) {
            return [];
        }

        $mapped = [];
        foreach ($this->deferredSeeds as $seed) {
            $this->commandSeqToCreatedTurn[$seed->seq] = $createdTurnNo;
            $mapped[] = $seed;
        }
        $this->deferredSeeds = [];

        return $mapped;
    }

    public function deferSeed(RunEvent $event): void
    {
        if (!$this->isTurnSeedingCommandEvent($event)) {
            throw new \LogicException('Only turn-seeding commands can be deferred.');
        }

        $this->deferredSeeds[] = $event;
        $this->turnSeedingCommandTurns[$event->seq] = $event->turnNo;
    }

    /**
     * Drop unmatched post-completion pending launches suppressed by history_select.
     *
     * @return list<int> suppressed command sequences
     */
    public function suppressUnmatchedPendingSeeds(RunEvent $historySelectEvent): array
    {
        if (RunEventTypeEnum::HistoryPositionSet->value !== $historySelectEvent->type) {
            return [];
        }

        $payload = $historySelectEvent->payload;
        $positionTurnNo = (int) ($payload['position_turn_no'] ?? 0);
        $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : '';
        if ('history_select' !== $reason || $positionTurnNo <= 0) {
            return [];
        }

        $turnCompletionSeq = $this->turnCompletionSeqByTurn[$positionTurnNo] ?? 0;
        if ($turnCompletionSeq <= 0 || $historySelectEvent->seq <= $turnCompletionSeq) {
            return [];
        }

        $suppressed = [];
        $kept = [];
        foreach ($this->deferredSeeds as $seed) {
            if ($seed->turnNo !== $positionTurnNo) {
                $kept[] = $seed;
                continue;
            }
            if ($seed->seq <= $turnCompletionSeq || $seed->seq >= $historySelectEvent->seq) {
                $kept[] = $seed;
                continue;
            }
            if (isset($this->commandSeqToCreatedTurn[$seed->seq])) {
                $kept[] = $seed;
                continue;
            }
            $suppressed[] = $seed->seq;
            unset($this->turnSeedingCommandTurns[$seed->seq]);
        }
        $this->deferredSeeds = $kept;

        return $suppressed;
    }

    /**
     * Drop deferred seeds that can no longer map into the retained prefix.
     */
    public function dropDeferredSeedsAfterDiscard(int $afterTurnNo): void
    {
        if ([] === $this->deferredSeeds) {
            return;
        }

        $kept = [];
        foreach ($this->deferredSeeds as $seed) {
            if ($seed->turnNo > $afterTurnNo) {
                unset($this->turnSeedingCommandTurns[$seed->seq]);
                continue;
            }
            $kept[] = $seed;
        }
        $this->deferredSeeds = $kept;

        foreach (array_keys($this->turnCompletionSeqByTurn) as $turnNo) {
            if ($turnNo > $afterTurnNo) {
                unset($this->turnCompletionSeqByTurn[$turnNo]);
            }
        }
    }

    public function shouldIncludeTurn(int $turnNo, int $positionTurnNo): bool
    {
        if ($turnNo <= 0) {
            return true;
        }

        return \in_array($turnNo, $this->provisionalHistory()->retainedTurnNosThrough($positionTurnNo), true);
    }

    public function shouldIncludeEvent(RunEvent $event, int $positionTurnNo): bool
    {
        if (0 === $event->turnNo) {
            return true;
        }

        if ($this->shouldIncludeTurn($event->turnNo, $positionTurnNo)) {
            if ($this->isTurnSeedingCommandEvent($event)) {
                // Seeds are applied only through deferred mapping / suppression.
                return false;
            }

            return true;
        }

        return $this->isHistoryMetadataEvent($event);
    }

    public function finishSnapshot(): HistoryProjectionSnapshot
    {
        if ($this->finished) {
            throw new \LogicException('HistoryReplayStreamPlanner snapshot already finished.');
        }

        $this->finished = true;
        $this->finishedSnapshot = $this->historyBuilder->finishSnapshot($this->maxSeq);

        return $this->finishedSnapshot;
    }

    /**
     * Remaining deferred seeds that never received a TurnAdvanced mapping.
     *
     * Tip unfinished launches stay included unless history_select suppression
     * already removed them. Callers apply or drop them before finishing.
     *
     * @return list<RunEvent>
     */
    public function drainDeferredSeeds(): array
    {
        $seeds = $this->deferredSeeds;
        $this->deferredSeeds = [];

        return $seeds;
    }

    public function maxSeq(): int
    {
        return $this->maxSeq;
    }

    public function provisionalHistory(): HistoryDTO
    {
        return new HistoryDTO(
            retainedTurnNos: $this->historyBuilder->provisionalRetainedTurnNos(),
            promptsByTurnNo: $this->historyBuilder->provisionalPromptsByTurnNo(),
            positionTurnNo: $this->historyBuilder->provisionalPositionTurnNo(),
        );
    }

    public function tipTurnNo(): int
    {
        $retained = $this->historyBuilder->provisionalRetainedTurnNos();
        if ([] === $retained) {
            return 0;
        }

        return $retained[array_key_last($retained)];
    }

    public function isShellOnlySession(): bool
    {
        return $this->hasBashTool && !$this->hasLlmConversation && $this->terminalCompleted;
    }

    public function terminalActivityReason(): ?string
    {
        return $this->latestAgentEndReason;
    }

    public function shouldSuppressTerminalActivityForInProgressCompaction(): bool
    {
        if (null === $this->lastCompactionStartedSeq) {
            return false;
        }

        if (null !== $this->lastCompactionTerminalSeq
            && $this->lastCompactionTerminalSeq >= $this->lastCompactionStartedSeq) {
            return false;
        }

        return $this->lastCompactionStartedSeq >= ($this->lastAgentEndSeq ?? 0);
    }

    public function isTurnSeedingCommandEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::AgentCommandQueued->value,
            RunEventTypeEnum::AgentCommandApplied->value,
        ], true);
    }

    private function observeResumeMetadata(RunEvent $event): void
    {
        $type = $event->type;
        $payload = $event->payload;

        if (RunEventTypeEnum::RunStarted->value === $type || RunEventTypeEnum::LlmStepCompleted->value === $type) {
            $this->hasLlmConversation = true;
        }

        if (RunEventTypeEnum::ToolExecutionStart->value === $type && 'bash' === (string) ($payload['tool_name'] ?? '')) {
            $this->hasBashTool = true;
        }

        if (RunEventTypeEnum::AgentEnd->value === $type) {
            $reason = \is_string($payload['reason'] ?? null) ? $payload['reason'] : 'completed';
            $this->latestAgentEndReason = $reason;
            $this->lastAgentEndSeq = $event->seq;
            if ('completed' === $reason) {
                $this->terminalCompleted = true;
            }
        }

        if (RunEventTypeEnum::ContextCompactionStarted->value === $type) {
            $this->lastCompactionStartedSeq = $event->seq;
        }

        if (\in_array($type, [
            RunEventTypeEnum::ContextCompacted->value,
            RunEventTypeEnum::ContextCompactionFailed->value,
        ], true)) {
            $this->lastCompactionTerminalSeq = null === $this->lastCompactionTerminalSeq
                ? $event->seq
                : max($this->lastCompactionTerminalSeq, $event->seq);
        }
    }

    private function trackCompletionMarkers(RunEvent $event): void
    {
        if (!\in_array($event->type, [
            RunEventTypeEnum::AgentEnd->value,
            RunEventTypeEnum::LlmStepCompleted->value,
        ], true) || $event->turnNo <= 0) {
            return;
        }

        $current = $this->turnCompletionSeqByTurn[$event->turnNo] ?? 0;
        $this->turnCompletionSeqByTurn[$event->turnNo] = max($current, $event->seq);
    }

    private function isHistoryMetadataEvent(RunEvent $event): bool
    {
        return \in_array($event->type, [
            RunEventTypeEnum::HistoryPositionSet->value,
            RunEventTypeEnum::HistoryTailDiscarded->value,
        ], true);
    }
}
