<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

enum BashOwnershipStatusEnum: string
{
    case Accepted = 'accepted';
    case Retired = 'retired';
    case RegistrationMissing = 'registration_missing';
    case ProcessMissing = 'process_missing';
    case ProcessFinished = 'process_finished';
    case SessionMissing = 'session_missing';
    case InputConflict = 'input_conflict';
    case InvalidInput = 'invalid_input';
}
