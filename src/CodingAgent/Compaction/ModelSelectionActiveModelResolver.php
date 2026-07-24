<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\CodingAgent\Config\ModelSelectionService;

/**
 * Session/default model lookup for configuration surfaces (UI, compaction config).
 *
 * Execution identity for LLM steps is canonical RunState.model scheduled onto
 * ExecuteLlmStep. This resolver must not classify run IDs as child/parent domains
 * or look up deferred child definition models.
 */
final readonly class ModelSelectionActiveModelResolver implements ActiveModelResolverInterface
{
    public function __construct(
        private ModelSelectionService $modelSelectionService,
    ) {
    }

    public function resolveActiveModel(string $runId): ?string
    {
        return $this->modelSelectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: $runId,
        )?->toString();
    }

    public function getActiveModel(string $runId): ?string
    {
        return $this->resolveActiveModel($runId);
    }
}
