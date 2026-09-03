<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Terminal;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\Tui\Terminal\PiStyleScreenWriter;
use Ineersa\Tui\Terminal\PiStyleScreenWriterAliasInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Alias install proof.
 *
 * Process-global class_alias state means this class must run in its own
 * process so other TUI tests keep the stock Symfony ScreenWriter FQCN.
 */
#[CoversClass(PiStyleScreenWriterAliasInstaller::class)]
#[RunTestsInSeparateProcesses]
final class PiStyleScreenWriterAliasInstallerTest extends TestCase
{
    public function testInstallAliasesScreenWriterBeforeFirstTuiConstruction(): void
    {
        $this->assertFalse(class_exists('Symfony\\Component\\Tui\\Render\\ScreenWriter', false));

        $logger = new TestLogger();
        PiStyleScreenWriterAliasInstaller::install($logger);

        $this->assertTrue(PiStyleScreenWriterAliasInstaller::isInstalled());
        $this->assertTrue(class_exists('Symfony\\Component\\Tui\\Render\\ScreenWriter', false));

        $reflection = new \ReflectionClass('Symfony\\Component\\Tui\\Render\\ScreenWriter');
        $this->assertSame(PiStyleScreenWriter::class, $reflection->getName());

        // Idempotent reinstall must keep ownership.
        PiStyleScreenWriterAliasInstaller::install($logger);
        $this->assertTrue(PiStyleScreenWriterAliasInstaller::isInstalled());

        $info = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'info' === $record['level'],
        ));
        $this->assertCount(1, $info);
        $this->assertSame('tui.screen_writer.alias.activated', $info[0]['message']);
        $this->assertSame('tui', $info[0]['context']['component'] ?? null);
        $this->assertSame('tui.screen_writer.alias.activated', $info[0]['context']['event_type'] ?? null);
        $this->assertArrayNotHasKey('prompt', $info[0]['context']);
        $this->assertArrayNotHasKey('transcript', $info[0]['context']);
    }
}
