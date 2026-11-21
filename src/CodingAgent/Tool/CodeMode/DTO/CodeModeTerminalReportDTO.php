<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode\DTO;

use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final readonly class CodeModeTerminalReportDTO
{
    public function __construct(public string $invocationId, public ToolPayloadReferenceDTO $payload)
    {
    }
}
