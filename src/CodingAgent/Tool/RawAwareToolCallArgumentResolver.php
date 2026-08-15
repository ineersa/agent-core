<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Native argument-resolver decorator for raw-array tool handlers.
 *
 * Typed DTO handlers are resolved by the inner native resolver. Raw-array
 * handlers (MCP tools, public extension adapters, settings) receive the
 * provider argument map verbatim under their single `$arguments` parameter —
 * Symfony AI's resolver requires tool-call arguments keyed by parameter name,
 * and dynamic runtime schemas cannot be reflected into DTOs.
 *
 * Tools are routed by the `raw_arguments` flag on their native Tool metadata,
 * which RegistryBackedToolbox/IsolatedAgentToolbox set for definitions that
 * carry a runtime-provided parametersJsonSchema.
 *
 * No argument validation happens here: missing/unknown/constraint handling
 * for raw-array tools is delegated to the MCP/extension handler or server.
 */
final readonly class RawAwareToolCallArgumentResolver implements ToolCallArgumentResolverInterface
{
    public function __construct(
        private ToolCallArgumentResolverInterface $inner,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveArguments(Tool $metadata, ToolCall $toolCall): array
    {
        if (true === $metadata->getMetadataValue('raw_arguments', false)) {
            // Raw handlers are required to declare exactly one parameter named
            // `$arguments` (McpToolHandler, ExtensionToolHandlerAdapter).
            return ['arguments' => $toolCall->getArguments()];
        }

        return $this->inner->resolveArguments($metadata, $toolCall);
    }
}
