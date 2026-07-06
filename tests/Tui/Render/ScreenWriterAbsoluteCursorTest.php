<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Render;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenWriterAbsoluteCursorTest extends TestCase
{
    #[Test]
    public function patchedScreenWriterSourceUsesAbsoluteCup(): void
    {
        $path = \dirname(__DIR__, 3).'/vendor/symfony/tui/Render/ScreenWriter.php';
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $this->assertStringContainsString('CUP: absolute row/col', $contents);
        $this->assertStringContainsString('($targetCol + 1).\'H\'', $contents);
        $this->assertStringNotContainsString('$rowDelta = $targetRow - $this->hardwareCursorRow', $contents);
    }
}
