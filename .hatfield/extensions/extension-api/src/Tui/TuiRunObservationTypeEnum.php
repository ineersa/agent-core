<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

enum TuiRunObservationTypeEnum
{
    case TurnStarted;
    case TurnCompleted;
    case TurnFailed;
    case TurnCancelled;
    case QuestionRequested;
    case ApprovalRequested;
    case InputResolved;
}
