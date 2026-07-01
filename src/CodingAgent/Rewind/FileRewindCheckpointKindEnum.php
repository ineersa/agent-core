<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Rewind;

enum FileRewindCheckpointKindEnum: string
{
    case UserBoundary = 'user_boundary';
    case AssistantBoundary = 'assistant_boundary';
    case RestoreUndo = 'restore_undo';
}
