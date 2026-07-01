<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

/**
 * Narrow port for conversation rewind used by /tree navigation orchestration.
 */
interface ConversationRewindInterface
{
    /**
     * @return array{leafSetSeq: int, rebuiltState: object}
     */
    public function rewind(string $runId, int $targetTurnNo): array;
}
