<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\NullInteractiveCommandHost;
use PHPUnit\Framework\TestCase;

final class NullInteractiveCommandHostTest extends TestCase
{
    public function testFileRewindPickerIsNotAvailable(): void
    {
        $host = new NullInteractiveCommandHost();
        self::assertFalse($host->isFileRewindPickerAvailable());
    }
}
