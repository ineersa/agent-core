<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;

/**
 * Decorates CodingAgentToolSetResolver so MCP dynamic tools are
 * registered from the session catalog before the active tool
 * snapshot is built.
 *
 * When runId is non-empty, calls McpToolRegistrar::registerForRun()
 * before delegating to the inner resolver.  Missing catalog is a
 * no-op — only tools from a successfully written catalog are
 * registered.
 *
 * This hits the critical process-local points:
 *  - LLM worker before DynamicToolDescriptionProcessor builds
 *    tool schemas for the LLM.
 *  - Command handler before LlmStepResultHandler resolves schemas
 *    and execution policies.
 *  - Tool worker allowlist path before toolbox execution.
 */
final readonly class McpCatalogRegisteringToolSetResolver implements ToolSetResolverInterface
{
    public function __construct(
        private ToolSetResolverInterface $inner,
        private McpToolRegistrar $mcpToolRegistrar,
    ) {
    }

    public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
    {
        // Register MCP tools from the session catalog only when run
        // context is available.  Missing runId means the resolver is
        // being called outside a run context (e.g. container validation);
        // MCP tools are session-scoped and not relevant there.
        if (null !== $runId && '' !== $runId) {
            $this->mcpToolRegistrar->registerForRun($runId);
        }

        return $this->inner->resolve($toolsRef, $turnNo, $runId);
    }
}
