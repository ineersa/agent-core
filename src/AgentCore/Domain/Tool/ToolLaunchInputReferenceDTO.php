<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/** Private runtime payload identity, never a caller-supplied path. */
final readonly class ToolLaunchInputReferenceDTO
{
    public function __construct(
        public string $kind,
        public string $producingRunId,
        public int $producingTurnNo,
        public string $producingStepId,
        public string $toolCallId,
        public string $producingModel,
        public string $sha256,
        public int $bytes,
    ) {
    }
}
