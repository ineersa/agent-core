<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode\DTO;

use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final readonly class CodeModeReplyDTO
{
    public function __construct(public string $callId, public ToolPayloadReferenceDTO $payload)
    {
    }
}
