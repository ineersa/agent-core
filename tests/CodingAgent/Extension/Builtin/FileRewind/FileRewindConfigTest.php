<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindConfig;
use PHPUnit\Framework\TestCase;

final class FileRewindConfigTest extends TestCase
{
    public function testDefaultsEnabledTrue(): void
    {
        $config = FileRewindConfig::fromSettings([]);
        self::assertTrue($config->enabled);
        self::assertSame(100, $config->maxRetainedTurns);
    }
}
