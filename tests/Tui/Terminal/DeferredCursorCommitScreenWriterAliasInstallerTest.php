<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\Tui\Terminal\DeferredCursorCommitScreenWriter;
use Ineersa\Tui\Terminal\DeferredCursorCommitScreenWriterAliasInstaller;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeferredCursorCommitScreenWriterAliasInstallerTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function itInstallsTheAppOwnedWriterBeforeSymfonyLoadsIt(): void
    {
        DeferredCursorCommitScreenWriterAliasInstaller::install();

        $this->assertSame(
            DeferredCursorCommitScreenWriter::class,
            (new \ReflectionClass('Symfony\\Component\\Tui\\Render\\ScreenWriter'))->getName(),
        );
    }
}
