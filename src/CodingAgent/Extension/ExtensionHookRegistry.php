<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;

/**
 * Internal registry for tool call/result hooks registered by extensions.
 *
 * Hooks are stored in registration order. This registry is used by:
 * - ExtensionToolRegistryBridge to receive hooks from the public ExtensionApi
 * - Future ToolHookDispatcher (EXT-HOOK-04) to iterate and dispatch hooks
 *   during tool execution.
 *
 * @internal this is app-internal wiring, not part of the public ExtensionApi
 */
final class ExtensionHookRegistry
{
    /**
     * Registered tool call hooks, in registration order.
     *
     * @var list<ToolCallHookInterface>
     */
    private array $toolCallHooks = [];

    /**
     * Registered tool result hooks, in registration order.
     *
     * @var list<ToolResultHookInterface>
     */
    private array $toolResultHooks = [];

    public function addToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = $hook;
    }

    /**
     * @return list<ToolCallHookInterface>
     */
    public function toolCallHooks(): array
    {
        return $this->toolCallHooks;
    }

    public function addToolResultHook(ToolResultHookInterface $hook): void
    {
        $this->toolResultHooks[] = $hook;
    }

    /**
     * @return list<ToolResultHookInterface>
     */
    public function toolResultHooks(): array
    {
        return $this->toolResultHooks;
    }
}
