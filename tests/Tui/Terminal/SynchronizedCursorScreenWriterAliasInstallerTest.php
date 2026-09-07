<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\SynchronizedCursorScreenWriter;
use Ineersa\Tui\Terminal\SynchronizedCursorScreenWriterAliasInstaller;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SynchronizedCursorScreenWriterAliasInstallerTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function itInstallsTheAppOwnedWriterBeforeSymfonyLoadsIt(): void
    {
        SynchronizedCursorScreenWriterAliasInstaller::install();

        $this->assertSame(
            SynchronizedCursorScreenWriter::class,
            (new \ReflectionClass('Symfony\\Component\\Tui\\Render\\ScreenWriter'))->getName(),
        );
    }
}
