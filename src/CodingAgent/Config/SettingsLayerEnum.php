<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

enum SettingsLayerEnum: string
{
    case Defaults = 'defaults';
    case User = 'user';
    case Project = 'project';
}
