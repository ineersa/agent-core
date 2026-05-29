<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Hook interface invoked before a tool call is executed.
 *
 * Extensions implementing this interface and registering via
 * ExtensionApiInterface::registerToolCallHook() receive a ToolCallContextDTO
 * describing the pending tool invocation. The hook returns a ToolCallDecisionDTO
 * to allow, block, or replace the result of the tool call.
 *
 * Hooks run in registration order. The first non-allow decision wins.
 *
 * @see ToolCallContextDTO
 * @see ToolCallDecisionDTO
 */
interface ToolCallHookInterface
{
    public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO;
}
