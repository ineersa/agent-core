<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ToolCatalogContext
{
    public function __construct(
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $stepId = null,
        public ?string $contextRef = null,
        public ?string $toolsRef = null,
    ) {
    }
}
