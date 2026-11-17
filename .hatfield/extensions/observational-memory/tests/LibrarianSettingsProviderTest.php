<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class LibrarianSettingsProviderTest extends TestCase
{
    public function testAnonymousDefaultsAndExplicitToken(): void
    {
        $settings = OmSettings::fromArray(['librarian' => ['url' => 'http://localhost/_mcp']]);
        $this->assertSame(30, $settings->librarian->timeoutSeconds);
        $this->assertNull($settings->librarian->token);
        $this->assertSame('test-token', OmSettings::fromArray(['librarian' => ['url' => 'http://localhost/_mcp', 'token' => 'test-token']])->librarian->token);
    }

    public function testEnvironmentTokenIsResolvedAtBoundary(): void
    {
        $old = getenv('OM_TEST_TOKEN');
        try {
            putenv('OM_TEST_TOKEN=fixture-token');
            $this->assertSame('fixture-token', OmSettings::fromArray(['librarian' => ['url' => 'http://localhost/_mcp', 'token' => 'env:OM_TEST_TOKEN']])->librarian->token);
        } finally {
            putenv(false === $old ? 'OM_TEST_TOKEN' : 'OM_TEST_TOKEN='.$old);
        }
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsFailClosed(array $raw): void
    {
        $this->expectException(InvalidConfigurationException::class);
        OmSettings::fromArray($raw);
    }

    public static function invalidSettings(): iterable
    {
        yield 'absent' => [[]];
        yield 'null' => [['librarian' => null]];
        yield 'empty' => [['librarian' => []]];
        yield 'relative' => [['librarian' => ['url' => '/_mcp']]];
        yield 'credentials' => [['librarian' => ['url' => 'http://user:pass@localhost/_mcp']]];
        yield 'query' => [['librarian' => ['url' => 'http://localhost/_mcp?key=value']]];
        yield 'fragment' => [['librarian' => ['url' => 'http://localhost/_mcp#fragment']]];
        yield 'protocol' => [['librarian' => ['url' => 'file:///tmp/mcp']]];
        yield 'zero timeout' => [['librarian' => ['url' => 'http://localhost/_mcp', 'timeout_seconds' => 0]]];
        yield 'large timeout' => [['librarian' => ['url' => 'http://localhost/_mcp', 'timeout_seconds' => 301]]];
        yield 'token injection' => [['librarian' => ['url' => 'http://localhost/_mcp', 'token' => "bad\r\ntoken"]]];
        yield 'removed settings' => [['model' => 'test', 'librarian' => ['url' => 'http://localhost/_mcp']]];
    }
}
