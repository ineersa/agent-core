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

    public function getActiveModel(string $runId): ?string
    {
        return $this->modelSelectionService->getCurrentModel($runId)?->toString();
    }
}
