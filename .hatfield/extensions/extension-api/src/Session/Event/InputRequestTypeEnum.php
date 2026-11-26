<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

enum InputRequestTypeEnum
{
    case Question;
    case Approval;
}
