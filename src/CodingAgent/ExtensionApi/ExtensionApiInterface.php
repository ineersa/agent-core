<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Public API surface that Hatfield exposes to enabled extensions.
 *
 * Extensions receive this interface via HatfieldExtensionInterface::register()
 * and may call registerTool() to contribute permanent tools, and
 * registerToolCallHook() / registerToolResultHook() to intercept tool
 * execution lifecycle.
 *
 * This is the stable public contract for extension authors. All methods are
 * optional for v1; additional hooks may be added later without breaking
 * existing extensions.
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

    /**
     * Register a hook that is invoked before each tool call.
     *
     * Hooks run in registration order. The first non-Allow decision wins.
     *
     * @param ToolCallHookInterface $hook the hook implementation
     */
    public function registerToolCallHook(ToolCallHookInterface $hook): void;

    /**
     * Register a hook that is invoked after each tool call completes.
     *
     * Hooks run in registration order. Each hook sees the latest result state.
     *
     * @param ToolResultHookInterface $hook the hook implementation
     */
    public function registerToolResultHook(ToolResultHookInterface $hook): void;
}
