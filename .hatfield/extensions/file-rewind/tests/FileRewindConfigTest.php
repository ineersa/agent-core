<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\HatfieldExt\FileRewind\FileRewindConfig;
use PHPUnit\Framework\TestCase;

final class FileRewindConfigTest extends TestCase
{
    public function testDefaultsEnabledTrue(): void
    {
        $config = FileRewindConfig::fromSettings([]);
        $this->assertTrue($config->enabled);
        $this->assertSame(100, $config->maxRetainedTurns);
    }
}
