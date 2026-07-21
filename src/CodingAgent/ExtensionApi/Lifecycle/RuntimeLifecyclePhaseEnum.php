<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Owning runtime process lifecycle phase notifications.
 *
 * Start/stop are emitted once per owning headless controller process, not per
 * run and not from every Messenger worker.
 */
enum RuntimeLifecyclePhaseEnum: string
{
    case Started = 'started';
    case Stopping = 'stopping';
}
