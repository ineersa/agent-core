<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode\DTO;

final readonly class CodeModeTerminalReportDTO
{
    public function __construct(public string $runId, public string $invocationId, public ?string $failure = null)
    {
    }
}
