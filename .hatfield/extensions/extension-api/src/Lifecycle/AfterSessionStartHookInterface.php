<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Hook invoked once when an interactive controller session starts.
 *
 * Runs in the controller process before the event loop, so
 * {@see \Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface::dispatchExtensionAgentJob()}
 * can enqueue work on the async extension_agent transport.
 */
interface AfterSessionStartHookInterface
{
    public function onAfterSessionStart(AfterSessionStartHookContextDTO $context): void;
}
