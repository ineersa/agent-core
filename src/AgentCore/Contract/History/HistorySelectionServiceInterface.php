<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\History;

use Ineersa\AgentCore\Domain\Run\RunState;

interface HistorySelectionServiceInterface
{
    /**
     * Position before a user prompt or after a final assistant answer (non-destructive).
     *
     * @return array{
     *     rebuiltState: RunState,
     *     positionEventSeq: int,
     *     selectedPromptTurnNo: int,
     *     editorPromptText: string
     * }
     */
    public function selectEntry(string $runId, int $targetTurnNo, string $commandId, string $role = 'user'): array;
}
