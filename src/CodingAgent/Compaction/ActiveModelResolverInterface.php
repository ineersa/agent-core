<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Resolves the active model reference for a given run/session.
 *
 * Thin abstraction over model selection so compaction services
 * can be tested without booting the full model-selection dependency tree.
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
