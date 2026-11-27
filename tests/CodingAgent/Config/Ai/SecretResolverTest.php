<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\Exception\AiConfigurationException;
use Ineersa\CodingAgent\Config\Ai\SecretResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretResolverTest extends TestCase
{
    #[DataProvider('values')]
    public function testResolvesValues(?string $input, ?string $environment, ?string $expected): void
    {
        $previous = getenv('HATFIELD_TEST_SECRET_RESOLVER');
        putenv(null === $environment ? 'HATFIELD_TEST_SECRET_RESOLVER' : 'HATFIELD_TEST_SECRET_RESOLVER='.$environment);
        try {
            $this->assertSame($expected, SecretResolver::resolve($input));
        } finally {
            putenv(false === $previous ? 'HATFIELD_TEST_SECRET_RESOLVER' : 'HATFIELD_TEST_SECRET_RESOLVER='.$previous);
        }
    }

    public static function values(): iterable
    {
        yield 'null' => [null, null, null];
        yield 'literal' => ['literal-key', null, 'literal-key'];
        yield 'empty literal' => ['', null, ''];
        yield 'set' => ['env:HATFIELD_TEST_SECRET_RESOLVER', 'secret', 'secret'];
        yield 'unset' => ['env:HATFIELD_TEST_SECRET_RESOLVER', null, null];
        yield 'empty' => ['env:HATFIELD_TEST_SECRET_RESOLVER', '', null];
        yield 'zero' => ['env:HATFIELD_TEST_SECRET_RESOLVER', '0', '0'];
        yield 'whitespace preserved' => ['env:HATFIELD_TEST_SECRET_RESOLVER', ' ', ' '];
        yield 'no recursive resolution' => ['env:HATFIELD_TEST_SECRET_RESOLVER', 'env:OTHER', 'env:OTHER'];
    }

    public function testRejectsBarePrefix(): void
    {
        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionMessage('bare "env:"');

        SecretResolver::resolve('env:');
    }
}
