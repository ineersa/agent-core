<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextPathFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextPathFilterTest extends TestCase
{
    #[Test]
    public function acceptsRelativePathAndRejectsAbsoluteOrTraversal(): void
    {
        $this->assertSame('src/Auth', JbcontextPathFilter::validate('src/Auth'));
        $this->assertNull(JbcontextPathFilter::validate(''));
        $this->assertNull(JbcontextPathFilter::validate(null));

        $this->expectException(\InvalidArgumentException::class);
        JbcontextPathFilter::validate('/tmp/evil');
    }

    #[Test]
    public function rejectsDotDotSegments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JbcontextPathFilter::validate('src/../etc');
    }
}
