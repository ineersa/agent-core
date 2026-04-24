<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ModelResolutionContext
{
    public function __construct(
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $stepId = null,
        public ?string $contextRef = null,
        public ?string $toolsRef = null,
    ) {
    }

    public function asToolCatalogContext(): ToolCatalogContext
    {
        return new ToolCatalogContext(
            runId: $this->runId,
            turnNo: $this->turnNo,
            stepId: $this->stepId,
            contextRef: $this->contextRef,
            toolsRef: $this->toolsRef,
        );
    }
}
