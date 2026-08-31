<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Typed terminal finalization request: artifact outcome to persist plus optional run/timeout context for child-kind presentation.
 */
final readonly class ChildRunTerminalFinalizationRequestDTO
{
    private function __construct(
        public ChildRunTerminalFinalizationKindEnum $kind,
        public ChildRunTerminalOutcomeDTO $artifactOutcome,
        public ?RunState $childRunState = null,
        public ?int $timeoutSeconds = null,
    ) {
    }

    public static function persistOnly(ChildRunTerminalOutcomeDTO $artifactOutcome): self
    {
        return new self(ChildRunTerminalFinalizationKindEnum::PersistOnly, $artifactOutcome);
    }
}
