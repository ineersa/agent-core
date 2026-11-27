<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Hook interface invoked after a tool call has been executed.
 *
 * Extensions implementing this interface and registering via
 * ExtensionApiInterface::registerToolResultHook() receive a ToolResultContextDTO
 * describing the completed tool invocation. The hook returns a ToolResultDecisionDTO
 * to keep or replace the result.
 *
 * Hooks run in registration order. Each hook sees the latest result state.
 * The host currently treats result hooks as observational: replacement
 * decisions are visible to later hooks but are not applied to the live tool
 * result.
 */
interface ToolResultHookInterface
{
    public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO;
}
