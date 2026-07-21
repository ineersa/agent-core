<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Receives owning runtime process start/stop notifications.
 *
 * Extensions may start or stop their own resources. Hatfield does not register
 * or supervise extension workers.
 */
interface RuntimeLifecycleHookInterface
{
    public function onRuntimeLifecycle(RuntimeLifecycleDTO $lifecycle): void;
}
