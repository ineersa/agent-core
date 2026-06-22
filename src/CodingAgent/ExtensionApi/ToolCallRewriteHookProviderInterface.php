<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Narrow provider interface for retrieving registered rewrite hooks.
 *
 * Implemented by ExtensionHookRegistry (AppExtension) and consumed by
 * RegistryBackedToolbox (AppTool) through the AppExtensionApi public contract,
 * avoiding a direct AppTool → AppExtension dependency.
 *
 * @see ToolCallRewriteHookInterface
 */
interface ToolCallRewriteHookProviderInterface
{
    /**
     * Get rewrite hooks for a specific tool name.
     *
     * Returns hooks registered for the exact tool name plus hooks
     * registered for the wildcard '*', in registration order.
     *
     * @return list<ToolCallRewriteHookInterface>
     */
    public function rewriteHooksForTool(string $toolName): array;
}
