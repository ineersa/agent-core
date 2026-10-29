<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

enum BashOwnershipActionEnum: string
{
    case Accept = 'accept';
    case Retire = 'retire';
}
