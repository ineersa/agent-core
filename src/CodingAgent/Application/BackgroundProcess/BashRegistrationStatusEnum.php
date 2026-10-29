<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

enum BashRegistrationStatusEnum: string
{
    case Registered = 'registered';
    case InvalidInput = 'invalid_input';
    case InputConflict = 'input_conflict';
    case SessionMissing = 'session_missing';
    case Retired = 'retired';
}
