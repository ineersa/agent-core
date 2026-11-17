<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Runtime\ObservationalMemoryConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ObservationalMemoryConfigurationTest extends TestCase
{
    public function testRawLegacySettingsNormalizeToOnlyLibrarian(): void
    {
        $result = (new Processor())->processConfiguration(new ObservationalMemoryConfiguration(), [[
            'storage' => ['database' => '.hatfield/extensions-data/observational-memory/om.sqlite'],
            'model' => 'provider/model',
            'observer' => ['renderer_version' => '1', 'threshold_tokens' => 1000],
            'reflector' => ['threshold_tokens' => 2000],
            'pools' => ['observer' => ['concurrency' => 1]],
            'librarian' => ['url' => 'http://localhost/_mcp'],
        ]]);
        $this->assertSame(['librarian' => ['url' => 'http://localhost/_mcp', 'timeout_seconds' => 30, 'token' => null]], $result);
    }

    #[DataProvider('legacyValues')]
    public function testLegacyContentsAreNeverValidated(mixed $value): void
    {
        $result = (new Processor())->processConfiguration(new ObservationalMemoryConfiguration(), [[
            'storage' => $value, 'model' => $value, 'observer' => $value, 'reflector' => $value, 'pools' => $value,
            'librarian' => ['url' => 'http://localhost/_mcp'],
        ]]);
        $this->assertSame(['librarian'], array_keys($result));
    }

    public static function legacyValues(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['invalid-old-value'];
        yield 'integer' => [42];
        yield 'boolean' => [false];
        yield 'invalid nesting' => [['unknown' => ['malformed' => null]]];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('invalidSettings')]
    public function testLegacyKeysDoNotRelaxOtherValidation(array $settings): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new ObservationalMemoryConfiguration(), [$settings + [
            'storage' => null, 'model' => 42, 'observer' => false, 'reflector' => 'old', 'pools' => [],
        ]]);
    }

    public static function invalidSettings(): iterable
    {
        yield 'missing librarian' => [[]];
        yield 'invalid librarian' => [['librarian' => ['url' => '/relative']]];
        yield 'null librarian' => [['librarian' => null]];
        yield 'unknown librarian setting' => [['librarian' => ['url' => 'http://localhost/_mcp', 'custom' => true]]];
        yield 'unrelated unknown key' => [['custom-internal-key' => null, 'librarian' => ['url' => 'http://localhost/_mcp']]];
    }
}
