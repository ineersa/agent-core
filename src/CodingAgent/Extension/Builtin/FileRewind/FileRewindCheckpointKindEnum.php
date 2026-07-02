<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

enum FileRewindCheckpointKindEnum: string
{
    case TurnBoundary = 'turn_boundary';
    case RestoreUndo = 'restore_undo';
}
