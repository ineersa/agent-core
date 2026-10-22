<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DTO;

use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\DTO\SubagentChildRunArtifactPublicationDTO;

final readonly class DeferredSubagentCompletionSourceDTO
{
    /** @param array{error: array<string, mixed>, details: array<string, mixed>}|null $errorEnvelope */
    public function __construct(
        public SubagentChildRunArtifactPublicationDTO $artifactPublication,
        public string $presentation,
        public bool $isError,
        public ?array $errorEnvelope,
    ) {
    }
}
