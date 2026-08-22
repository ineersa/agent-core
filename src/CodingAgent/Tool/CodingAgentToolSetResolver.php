<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;

/**
 * CodingAgent concrete resolver that maps AgentCore toolsRef values to
 * ToolRegistry active snapshots.
 *
 * In v1 the resolver ignores the toolsRef string and simply returns the
 * current active toolset (all permanent tools + current dynamic tools).
 * Future versions may use the ref to look up persisted per-turn tool
 * overrides stored alongside the run state.
 */
final readonly class CodingAgentToolSetResolver implements ToolSetResolverInterface
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
    ) {
    }

    public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
    {
        $toolNames = $this->toolRegistry->activeToolNames();

        // Build execution mode map from registered tool definitions.
        // Each ToolDefinitionDTO stores the execution mode set by the
        // tool author/provider. Default is Sequential.
        $executionModes = [];
        $timeoutSeconds = [];
        foreach ($this->toolRegistry->activeToolDefinitions() as $definition) {
            $executionModes[$definition->name] = $definition->executionMode->value;
            if (null !== $definition->timeoutSeconds && $definition->timeoutSeconds > 0) {
                $timeoutSeconds[$definition->name] = $definition->timeoutSeconds;
            }
        }

        return new ActiveToolSet(
            toolNames: $toolNames,
            allowListNames: $toolNames,
            executionModes: $executionModes,
            timeoutSeconds: $timeoutSeconds,
        );
    }
}
