<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

/**
 * Typed terminal finalization request for the supported persist-only artifact path.
 */
final readonly class ChildRunTerminalFinalizationRequestDTO
{
    private function __construct(
        public ChildRunTerminalOutcomeDTO $artifactOutcome,
    ) {
    }

    public static function persistOnly(ChildRunTerminalOutcomeDTO $artifactOutcome): self
    {
        return new self($artifactOutcome);
    }
}
