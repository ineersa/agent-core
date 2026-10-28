<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

enum BashProcessActionEnum: string
{
    case Stop = 'stop';
    case Observe = 'observe';
    case SettleStop = 'settle_stop';
}
