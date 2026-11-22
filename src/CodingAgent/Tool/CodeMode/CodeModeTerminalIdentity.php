<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final class CodeModeTerminalIdentity
{
    public static function forInvocation(string $runId, string $invocationId): ToolPayloadReferenceDTO
    {
        $id = 'code-mode-terminal-'.$invocationId;

        // The first-result index uses correlation, not the as-yet unknown content hash.
        return new ToolPayloadReferenceDTO('result', $runId, 0, $id, 0, $id, $id, 0, null, str_repeat('0', 64), 1);
    }
}
