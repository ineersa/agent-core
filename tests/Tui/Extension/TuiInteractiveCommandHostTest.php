<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Extension;

use Ineersa\Tui\Extension\TuiInteractiveCommandHost;
use Ineersa\Tui\Runtime\FileRewind\TuiFileRewindPickerFlow;
use PHPUnit\Framework\TestCase;

final class TuiInteractiveCommandHostTest extends TestCase
{
    public function testPickerUnavailableUntilFlowIsWired(): void
    {
        $flow = new TuiFileRewindPickerFlow();
        $host = new TuiInteractiveCommandHost($flow);
        self::assertFalse($host->isFileRewindPickerAvailable());
        $flow->setOpenCallback(static function (string $sessionId): void {});
        self::assertTrue($host->isFileRewindPickerAvailable());
    }
}
