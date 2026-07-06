<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Single construction site for RunLeafChanged runtime events (process + in-process rewind).
 */
final class RunLeafChangedEventFactory
{
    public static function create(string $runId, int $leafSetSeq, int $targetTurnNo): RuntimeEvent
    {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunLeafChanged->value,
            runId: $runId,
            seq: $leafSetSeq,
            payload: [
                'turn_no' => $targetTurnNo,
                'leaf_set_seq' => $leafSetSeq,
            ],
        );
    }
}
