<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

enum FileRewindCheckpointKindEnum: string
{
    case TurnBoundary = 'turn_boundary';
    case RestoreUndo = 'restore_undo';
}
