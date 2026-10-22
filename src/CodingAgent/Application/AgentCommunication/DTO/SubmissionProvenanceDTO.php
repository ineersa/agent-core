<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\AgentCommunication\DTO;

final readonly class SubmissionProvenanceDTO
{
    public function __construct(public string $sourceRunId, public string $identity)
    {
    }
}
