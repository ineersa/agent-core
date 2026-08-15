<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Single construction site for run.history_position_changed (process + in-process).
 */
final class RunHistoryPositionChangedEventFactory
{
    /**
     * @param int    $positionTurnNo       Active tip after select (0 = before first turn)
     * @param int    $selectedPromptTurnNo User prompt row that was selected (for editor)
     * @param string $editorPromptText     Original prompt text to populate the editor
     */
    public static function create(
        string $runId,
        int $positionEventSeq,
        int $positionTurnNo,
        int $selectedPromptTurnNo = 0,
        string $editorPromptText = '',
    ): RuntimeEvent {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunHistoryPositionChanged->value,
            runId: $runId,
            seq: $positionEventSeq,
            payload: [
                'position_turn_no' => $positionTurnNo,
                'position_event_seq' => $positionEventSeq,
                'selected_prompt_turn_no' => $selectedPromptTurnNo,
                'editor_prompt_text' => $editorPromptText,
            ],
        );
    }
}
