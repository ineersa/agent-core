<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

/**
 * Backed string enum for background process lifecycle status.
 *
 * Persisted on the BackgroundProcess entity via Doctrine enumType mapping.
 * Display labels (e.g. showing exit code) are the responsibility of the
 * presentation layer (BgStatusTool), not of the storage layer.
 */
enum BackgroundProcessStatusEnum: string
{
    case Running = 'running';
    case Finished = 'finished';
    case FinishedUnclean = 'finished (unclean)';
    case Stopped = 'stopped';
}
