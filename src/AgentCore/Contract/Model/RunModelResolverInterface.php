<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Model;

/**
 * Resolves the active provider/model reference for a run (session id).
 *
 * AgentCore workers use this port; CodingAgent supplies the implementation
 * backed by session metadata and catalog defaults.
 */
interface RunModelResolverInterface
{
    /**
     * @return string|null Provider/model reference (e.g. "llama_cpp/flash") or null when unavailable
     */
    public function resolveActiveModel(string $runId): ?string;
}
