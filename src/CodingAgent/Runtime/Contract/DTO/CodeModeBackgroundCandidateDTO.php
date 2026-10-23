<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\DTO;

final readonly class CodeModeBackgroundCandidateDTO
{
    public function __construct(public string $identity, public string $toolCallId)
    {
    }
}
