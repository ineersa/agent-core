<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\CodingAgent\Config\ModelSelectionService;

/**
 * Resolves the active model reference for a given run/session.
 *
 * Thin abstraction over {@see ModelSelectionService} so
 * compaction services can be tested without booting the
 * full model-selection dependency tree.
 */
interface ActiveModelResolverInterface
{
    /**
     * Get the currently active model for the session, or null.
     *
     * @return string|null Provider/model reference (e.g. 'openai/gpt-4.1') or null
     */
    public function getActiveModel(string $runId): ?string;
}

/**
 * Production implementation backed by {@see ModelSelectionService}.
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
