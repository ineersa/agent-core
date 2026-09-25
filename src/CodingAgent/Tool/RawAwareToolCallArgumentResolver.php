<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Native argument-resolver decorator for Hatfield raw-array tool shapes.
 *
 * Raw-array handlers (MCP tools and public extension adapters) receive
 * the provider argument map verbatim under their single `$arguments` parameter
 * — Symfony AI's resolver requires tool-call arguments keyed by parameter name,
 * and dynamic runtime schemas cannot be reflected into DTOs.
 *
 * Typed DTO handlers use Symfony AI's `#[MapToolArguments]` path and are
 * delegated unchanged to the inner resolver.
 *
 * No argument validation happens here: missing/unknown/constraint handling
 * for raw-array tools is delegated to the MCP/extension handler or server,
 * and for typed tools to native denormalization +
 * ValidateToolCallArgumentsListener.
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
