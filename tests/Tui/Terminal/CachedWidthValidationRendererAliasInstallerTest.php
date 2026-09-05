<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\CachedWidthValidationRenderer;
use Ineersa\Tui\Terminal\CachedWidthValidationRendererAliasInstaller;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CachedWidthValidationRendererAliasInstallerTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function installAliasesSymfonyRendererToCachedWidthValidationRenderer(): void
    {
        CachedWidthValidationRendererAliasInstaller::install();

        $this->assertSame(
            CachedWidthValidationRenderer::class,
            (new \ReflectionClass('Symfony\\Component\\Tui\\Render\\Renderer'))->getName(),
        );
    }
}
