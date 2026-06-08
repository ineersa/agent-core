<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Utility;

use Ineersa\Tui\Utility\Clipboard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structural test for the Clipboard utility class.
 *
 * Does NOT exercise real clipboard processes — only verifies the class
 * exists and exposes the expected static API. The real clipboard fallback
 * chain is validated via manual testing across target environments.
 */
final class ClipboardTest extends TestCase
{
    #[Test]
    public function classExists(): void
    {
        $this->assertTrue(class_exists(Clipboard::class));
    }

    #[Test]
    public function hasStaticCopyMethod(): void
    {
        $this->assertTrue(method_exists(Clipboard::class, 'copy'));

        $reflection = new \ReflectionMethod(Clipboard::class, 'copy');
        $this->assertTrue($reflection->isStatic());
        $this->assertTrue($reflection->isPublic());
        $this->assertSame('bool', (string) $reflection->getReturnType());
    }
}
