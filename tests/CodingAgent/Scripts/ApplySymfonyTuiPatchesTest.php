<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Scripts;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplySymfonyTuiPatchesTest extends TestCase
{
    #[Test]
    public function screenWriterContainsAbsoluteCupAfterPatchScript(): void
    {
        $root = \dirname(__DIR__, 3);
        $script = $root.'/scripts/apply-symfony-tui-patches.php';
        $target = $root.'/vendor/symfony/tui/Render/ScreenWriter.php';

        $this->assertFileExists($script);
        $this->assertFileExists($target);

        $code = 0;
        passthru('php '.escapeshellarg($script), $code);
        $this->assertSame(0, $code);

        $contents = file_get_contents($target);
        $this->assertIsString($contents);
        $this->assertStringContainsString('CUP: absolute row/col', $contents);
    }
}
