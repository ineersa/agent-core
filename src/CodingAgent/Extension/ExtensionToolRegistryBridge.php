<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;

/**
 * Bridges public ExtensionApiInterface calls to the internal ToolRegistry.
 *
 * Extensions call registerTool(ToolRegistrationDTO) during their
 * HatfieldExtensionInterface::register() lifecycle. This bridge translates
 * each public DTO into a permanent tool registration on the CodingAgent
 * ToolRegistry, so extension-provided tools flow through the same active
 * tool set, provider schema exposure, execution allowlist, and prompt
 * metadata deduplication as built-in tools.
 *
 * This is an app-internal service. The ExtensionApi boundary remains pure:
 * ExtensionApi code (interfaces, DTOs) has no knowledge of ToolRegistry or
 * CodingAgent internals. The bridge sits in the AppExtension layer and is
 * the sole adapter between the two.
 */
final readonly class ExtensionToolRegistryBridge implements ExtensionApiInterface
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
    ) {
    }

    /**
     * Register a permanent tool from an extension.
     *
     * Maps the public ToolRegistrationDTO fields to ToolRegistryInterface::registerTool()
     * signatures, deriving a default prompt line from name and description
     * when the DTO does not provide a promptSummary.
     *
     * ToolRegistry::registerTool() handles idempotent re-registration,
     * empty-name/description validation, and prompt metadata deduplication.
     *
     * @throws \InvalidArgumentException on empty name or description
     */
    public function registerTool(ToolRegistrationDTO $tool): void
    {
        $handler = $tool->handler;

        if (!$handler instanceof ToolHandlerInterface) {
            throw new \InvalidArgumentException(\sprintf('Tool "%s" handler must be an instance of ToolHandlerInterface, got %s.', $tool->name, get_debug_type($handler)));
        }

        $this->toolRegistry->registerTool(
            name: $tool->name,
            description: $tool->description,
            parametersJsonSchema: $tool->parametersJsonSchema,
            handler: $handler,
            promptLine: $tool->promptSummary ?? \sprintf('%s: %s', $tool->name, $tool->description),
            promptGuidelines: $tool->promptGuidelines,
        );
    }

    /**
     * Register a tool call hook.
     *
     * Full hook storage and dispatch will be implemented by EXT-HOOK-02.
     */
    public function registerToolCallHook(ToolCallHookInterface $hook): void
    {
        // Reserved for EXT-HOOK-02: extension hook registry.
    }

    /**
     * Register a tool result hook.
     *
     * Full hook storage and dispatch will be implemented by EXT-HOOK-02.
     */
    public function registerToolResultHook(ToolResultHookInterface $hook): void
    {
        // Reserved for EXT-HOOK-02: extension hook registry.
    }
}
