<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\LibrarianSettingsProvider;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class LibrarianSettingsProviderTest extends TestCase
{
    public function testAbsentAndNullStayLocalAndConfiguredDefaultsSurviveVersionCopy(): void
    {
        $this->assertNull(OmSettings::fromArray([])->librarian);
        $this->assertNull(OmSettings::fromArray(['librarian' => null])->librarian);
        $settings = OmSettings::fromArray(['librarian' => ['url' => 'http://127.0.0.1:8093/_mcp']]);
        $this->assertSame(30, $settings->librarian->timeoutSeconds);
        $this->assertSame($settings->librarian, $settings->withRendererAndObserverVersions('2', '2')->librarian);
    }

    #[DataProvider('invalidSettings')]
    public function testMalformedConfiguredBackendFailsClosed(mixed $raw): void
    {
        $this->expectException(InvalidConfigurationException::class);
        LibrarianSettingsProvider::fromArray($raw);
    }

    public static function invalidSettings(): iterable
    {
        yield 'empty block' => [[]];
        yield 'missing url' => [['timeout_seconds' => 30]];
        yield 'relative url' => [['url' => '/_mcp']];
        yield 'credentials' => [['url' => 'http://user:secret@localhost/_mcp']];
        yield 'query' => [['url' => 'http://localhost/_mcp?secret=value']];
        yield 'fragment' => [['url' => 'http://localhost/_mcp#fragment']];
        yield 'wrong protocol' => [['url' => 'file:///tmp/mcp']];
        yield 'header injection' => [['url' => "http://localhost/\r\nHeader:value"]];
        yield 'timeout zero' => [['url' => 'http://localhost/_mcp', 'timeout_seconds' => 0]];
        yield 'timeout unbounded' => [['url' => 'http://localhost/_mcp', 'timeout_seconds' => 301]];
    }
}
