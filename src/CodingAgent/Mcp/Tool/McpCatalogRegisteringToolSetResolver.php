<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Psr\Log\LoggerInterface;

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
 *
 * MCP registration failures are caught and logged — the resolver
 * contract requires returning a (possibly empty) ActiveToolSet, not
 * throwing, because MCP catalog registration is optional.
 */
final readonly class McpCatalogRegisteringToolSetResolver implements ToolSetResolverInterface
{
    public function __construct(
        private ToolSetResolverInterface $inner,
        private McpToolRegistrar $mcpToolRegistrar,
        private SubagentRunMetadataReader $metadataReader,
        private McpToolCatalogStoreInterface $catalogStore,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
    {
        // Register MCP tools from the session catalog only when run
        // context is available.  Missing runId means the resolver is
        // being called outside a run context (e.g. container validation);
        // MCP tools are session-scoped and not relevant there.
        if (null !== $runId && '' !== $runId) {
            try {
                $catalogRunId = $this->resolveCatalogRunId($runId);
                $this->mcpToolRegistrar->registerUsingCatalogFrom($runId, $catalogRunId);
            } catch (\Throwable $e) {
                // MCP catalog registration is optional — a failure
                // here must not prevent the resolver from returning
                // a valid toolset.  Log a structured warning and
                // fall through to the inner resolver.
                $this->logger->warning(
                    \sprintf(
                        'MCP tool registration failed for run "%s": %s',
                        $runId,
                        $e->getMessage(),
                    ),
                    [
                        'component' => 'mcp',
                        'mcp_event' => 'resolver.register_failed',
                        'event_type' => 'resolver.register_failed',
                        'run_id' => $runId,
                        'session_id' => $runId,
                        'error_class' => $e::class,
                        'error_message' => $e->getMessage(),
                    ],
                );
            }
        }

        return $this->inner->resolve($toolsRef, $turnNo, $runId);
    }

    private function resolveCatalogRunId(string $runId): string
    {
        if ($this->metadataReader->isAgentChild($runId)) {
            $parentRunId = $this->metadataReader->readParentRunId($runId);
            if (null !== $parentRunId) {
                if (null !== $this->catalogStore->read($runId)) {
                    return $runId;
                }

                return $parentRunId;
            }
        }

        return $runId;
    }
}
