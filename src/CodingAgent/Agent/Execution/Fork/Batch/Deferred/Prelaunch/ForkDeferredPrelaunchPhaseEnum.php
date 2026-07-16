<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch;

enum ForkDeferredPrelaunchPhaseEnum: string
{
    case AwaitingCompaction = 'awaiting_compaction';
    case CompactionDispatched = 'compaction_dispatched';
    case ReadyForChildLaunch = 'ready_for_child_launch';
    case Failed = 'failed';
}
