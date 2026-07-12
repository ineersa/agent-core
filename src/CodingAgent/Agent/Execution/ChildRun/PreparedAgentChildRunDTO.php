<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Domain\Run\StartRunInput;

/**
 * Immutable launch specification for a foreground child run after preparation.
 *
 * Generic over child kind: carries display metadata and StartRunInput without
 * agent-catalog types so the same supervision boundary can serve future child kinds.
 */
final readonly class PreparedAgentChildRunDTO
{
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $artifactId,
        public string $displayName,
        public string $taskSummary,
        public ?string $definitionModel,
        public StartRunInput $startRunInput,
    ) {
    }
}
