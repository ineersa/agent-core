<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/** Shared scalar anchor transitions for prompt projection and replay planning. */
final class HistoryRetentionTracker
{
    /** @var list<int> */
    public array $retainedTurnNos = [];
    public int $positionTurnNo = 0;
    /** Ordered turns remain the presentation contract; membership must not scan
     * the growing list on every advance or position confirmation.
     *
     * @var array<int, true>
     */
    private array $retainedTurnSet = [];

    public function observe(RunEvent $event): void
    {
        if (RunEventTypeEnum::TurnAdvanced->value === $event->type) {
            $turn = (int) ($event->payload['turn_no'] ?? $event->turnNo);
            if ($turn > 0) {
                if (!isset($this->retainedTurnSet[$turn])) {
                    $this->retainedTurnNos[] = $turn;
                    $this->retainedTurnSet[$turn] = true;
                }
                $this->positionTurnNo = $turn;
            }
        } elseif (RunEventTypeEnum::HistoryPositionSet->value === $event->type) {
            $turn = (int) ($event->payload['position_turn_no'] ?? $event->turnNo);
            if (0 === $turn || isset($this->retainedTurnSet[$turn])) {
                $this->positionTurnNo = $turn;
            }
        } elseif (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
            $after = (int) ($event->payload['after_turn_no'] ?? 0);
            $this->retainedTurnNos = array_values(array_filter($this->retainedTurnNos, static fn (int $turn): bool => $turn <= $after));
            $this->retainedTurnSet = array_fill_keys($this->retainedTurnNos, true);
            if (0 === $after || [] === $this->retainedTurnNos) {
                $this->positionTurnNo = 0;
            } elseif (isset($this->retainedTurnSet[$after])) {
                $this->positionTurnNo = $after;
            } elseif ($this->positionTurnNo > $after) {
                $this->positionTurnNo = $this->retainedTurnNos[array_key_last($this->retainedTurnNos)];
            }
        }
    }
}
