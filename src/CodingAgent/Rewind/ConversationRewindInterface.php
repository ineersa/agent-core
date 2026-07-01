<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Narrow port for conversation rewind used by /tree navigation orchestration.
 */
interface ConversationRewindInterface
{
    /**
     * @return array{leafSetSeq: int, rebuiltState: RunState}
     */
    public function rewind(string $runId, int $targetTurnNo): array;
}
