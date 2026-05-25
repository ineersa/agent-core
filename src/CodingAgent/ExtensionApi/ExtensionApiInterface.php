<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Public API surface that Hatfield exposes to enabled extensions.
 *
 * Extensions receive this interface via HatfieldExtensionInterface::register()
 * and may call registerTool() to contribute permanent tools to the registry.
 *
 * This is the stable public contract for extension authors. All methods are
 * optional for v1; additional hooks (before/after tool call, etc.) may be
 * added later without breaking existing extensions.
 */
interface ExtensionApiInterface
{
    /**
     * Register a permanent tool with the Hatfield tool registry.
     *
     * Registered tools become part of the provider schema, execution allowlist,
     * and system prompt tool listing according to ToolRegistry policy.
     *
     * @param ToolRegistrationDTO $tool the tool definition to register
     */
    public function registerTool(ToolRegistrationDTO $tool): void;
}
