<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Narrow provider interface for retrieving registered rewrite hooks.
 *
 * Implemented by the host extension hook registry and consumed by host tool
 * execution through this public contract, so tool execution does not depend
 * on the extension registry implementation.
 *
 * @see ToolCallRewriteHookInterface
 */
interface ToolCallRewriteHookProviderInterface
{
    /**
     * Get rewrite hooks for a specific tool name.
     *
     * Returns hooks registered for the exact tool name, then hooks
     * registered for the wildcard '*', each group in registration order.
     *
     * @return list<ToolCallRewriteHookInterface>
     */
    public function rewriteHooksForTool(string $toolName): array;
}
