<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Runtime\Process\SourceTreeExecutableLocator;

final class TestProcessRuntime
{
    public static function source(): RuntimeProcessConfig
    {
        $root = \dirname(__DIR__, 3);

        return new RuntimeProcessConfig(new SourceTreeExecutableLocator($root), $root);
    }
}
