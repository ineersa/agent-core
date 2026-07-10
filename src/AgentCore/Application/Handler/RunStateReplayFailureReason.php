<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

enum RunStateReplayFailureReason
{
    case DuplicateSequences;
    case MissingSequences;
}
