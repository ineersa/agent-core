<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCliDiagnostic;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextCliDiagnosticTest extends TestCase
{
    #[Test]
    public function formatsArbitraryStderrWithoutClassifying(): void
    {
        $formatted = JbcontextCliDiagnostic::format("Authentication required\nfrom IsLoggedInGuard");

        $this->assertSame(
            'JB Context error: "Authentication required from IsLoggedInGuard"',
            $formatted,
        );
    }

    #[Test]
    public function returnsNullForEmptyStderr(): void
    {
        $this->assertNull(JbcontextCliDiagnostic::format(''));
        $this->assertNull(JbcontextCliDiagnostic::format("  \n\t  "));
    }

    #[Test]
    public function boundsLongStderr(): void
    {
        $long = str_repeat('a', JbcontextCliDiagnostic::MAX_CHARS + 50);
        $formatted = JbcontextCliDiagnostic::format($long);

        $this->assertNotNull($formatted);
        $this->assertStringStartsWith('JB Context error: "', $formatted);
        $this->assertStringEndsWith('…"', $formatted);
        $this->assertLessThanOrEqual(
            \strlen('JB Context error: "') + JbcontextCliDiagnostic::MAX_CHARS + \strlen('"'),
            mb_strlen($formatted),
        );
    }
}
