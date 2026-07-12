<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\CodingAgent\Config\ModelSelectionService;

/**
 * Production implementation of {@see ActiveModelResolverInterface}
 * backed by {@see ModelSelectionService}.
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
