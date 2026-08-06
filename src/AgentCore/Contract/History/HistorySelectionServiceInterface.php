<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\History;

use Ineersa\AgentCore\Domain\Run\RunState;

interface HistorySelectionServiceInterface
{
    /**
     * Position history for selected user prompt turn (non-destructive).
     *
     * @return array{
     *     rebuiltState: RunState,
     *     positionEventSeq: int,
     *     selectedPromptTurnNo: int,
     *     editorPromptText: string
     * }
     */
    public function selectPrompt(string $runId, int $targetPromptTurnNo): array;
}
