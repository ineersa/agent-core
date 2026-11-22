<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Agent;

enum ThinkingLevelEnum: string
{
    case Off = 'off';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Xhigh = 'xhigh';
    case Max = 'max';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
