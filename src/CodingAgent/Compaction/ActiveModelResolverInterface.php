<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Contract\Model\RunModelResolverInterface;

/**
 * Resolves the active model reference for a given run/session.
 *
 * Thin abstraction over model selection so compaction services
 * can be tested without booting the full model-selection dependency tree.
 */
interface ActiveModelResolverInterface extends RunModelResolverInterface
{
    /**
     * @deprecated prefer {@see resolveActiveModel()}; kept for compaction call sites
     */
    public function getActiveModel(string $runId): ?string;

    public function resolveActiveModel(string $runId): ?string;
}
