<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

/**
 * Single construction site for RunLeafChanged runtime events (process + in-process rewind).
 */
final class RunLeafChangedEventFactory
{
    /**
     * @param int    $retainedBoundaryTurnNo Active tip after select (0 = before first turn)
     * @param int    $selectedPromptTurnNo   User prompt row that was selected (for editor)
     * @param string $editorPromptText       Original prompt text to populate the editor
     */
    public static function create(
        string $runId,
        int $leafSetSeq,
        int $retainedBoundaryTurnNo,
        int $selectedPromptTurnNo = 0,
        string $editorPromptText = '',
    ): RuntimeEvent {
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunLeafChanged->value,
            runId: $runId,
            seq: $leafSetSeq,
            payload: [
                'turn_no' => $retainedBoundaryTurnNo,
                'leaf_set_seq' => $leafSetSeq,
                'selected_prompt_turn_no' => $selectedPromptTurnNo,
                'editor_prompt_text' => $editorPromptText,
            ],
        );
    }
}
