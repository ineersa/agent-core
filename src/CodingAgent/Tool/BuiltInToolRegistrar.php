<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Collects all HatfieldToolProviderInterface services and registers each
 * as a permanent tool in the ToolRegistryInterface.
 *
 * Providers are discovered via the `hatfield.tool_provider` autoconfiguration
 * tag. The registrar is triggered during Kernel::boot() so that all
 * built-in tools are available before any command or runtime service
 * accesses the registry.
 *
 * Each provider's handler is preserved as-is; the registrar does not wrap
 * or modify handlers.
 */
final readonly class BuiltInToolRegistrar
{
    /**
     * @param iterable<HatfieldToolProviderInterface> $providers Tagged service iterator
     */
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
        private iterable $providers = [],
    ) {
    }

    /**
     * Register all collected providers as permanent tools in the ToolRegistry.
     *
     * Intended to be called once during kernel boot. Idempotent only if
     * providers return the same definitions (ToolRegistry::registerTool()
     * handles identical re-registration as a no-op).
     */
    public function registerTools(): void
    {
        foreach ($this->providers as $provider) {
            $def = $provider->definition();

            $this->toolRegistry->registerTool(
                name: $def->name,
                description: $def->description,
                parametersJsonSchema: $def->parametersJsonSchema,
                handler: $def->handler,
                promptLine: $def->promptLine,
                promptGuidelines: $def->promptGuidelines,
            );
        }
    }
}
