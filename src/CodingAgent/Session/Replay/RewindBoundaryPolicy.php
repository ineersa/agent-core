<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Defines which post-completion input is abandoned when replay starts at a
 * rewound leaf. Both state and transcript replay must apply this same window.
 */
final readonly class RewindBoundaryPolicy
{
    /**
     * @param list<RunEvent> $events
     *
     * @return array{rewindSeq: int, completionSeq: int}
     */
    public function forTarget(array $events, int $targetTurnNo): array
    {
        if ($targetTurnNo <= 0) {
            return ['rewindSeq' => 0, 'completionSeq' => 0];
        }

        $rewindSeq = 0;
        foreach ($events as $event) {
            if (RunEventTypeEnum::LeafSet->value !== $event->type
                || 'rewind' !== ($event->payload['reason'] ?? null)
                || $targetTurnNo !== (int) ($event->payload['turn_no'] ?? 0)) {
                continue;
            }

            $rewindSeq = max($rewindSeq, $event->seq);
        }

        if (0 === $rewindSeq) {
            return ['rewindSeq' => 0, 'completionSeq' => 0];
        }

        $completionSeq = 0;
        foreach ($events as $event) {
            if ($event->turnNo !== $targetTurnNo || $event->seq >= $rewindSeq) {
                continue;
            }

            if (\in_array($event->type, [
                RunEventTypeEnum::AgentEnd->value,
                RunEventTypeEnum::LlmStepCompleted->value,
            ], true)) {
                $completionSeq = max($completionSeq, $event->seq);
            }
        }

        return ['rewindSeq' => $rewindSeq, 'completionSeq' => $completionSeq];
    }

    /**
     * Commands on the target turn after completion and before rewind launched
     * an abandoned child branch and are not part of the target replay.
     *
     * @param array{rewindSeq: int, completionSeq: int} $boundary
     */
    public function isAbandonedTargetCommand(
        RunEvent $event,
        int $targetTurnNo,
        array $boundary,
    ): bool {
        return $event->turnNo === $targetTurnNo
            && $boundary['completionSeq'] > 0
            && $event->seq > $boundary['completionSeq']
            && $event->seq < $boundary['rewindSeq']
            && \in_array($event->type, [
                RunEventTypeEnum::AgentCommandQueued->value,
                RunEventTypeEnum::AgentCommandApplied->value,
            ], true);
    }
}
