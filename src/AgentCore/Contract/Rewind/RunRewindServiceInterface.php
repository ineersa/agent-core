<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Rewind;

use Ineersa\AgentCore\Domain\Run\RunState;

interface RunRewindServiceInterface
{
    /**
     * Position history for selected user prompt turn (non-destructive).
     *
     * @return array{
     *     rebuiltState: RunState,
     *     leafSetSeq: int,
     *     selectedPromptTurnNo?: int,
     *     editorPromptText?: string
     * }
     */
    public function rewind(string $runId, int $targetTurnNo): array;
}
