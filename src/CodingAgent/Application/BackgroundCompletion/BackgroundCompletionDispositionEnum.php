<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

enum BackgroundCompletionDispositionEnum: string
{
    case Pending = 'pending';
    case Forwarding = 'forwarding';
    case Consumed = 'consumed';
}
