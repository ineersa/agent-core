<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\CastorLlmMode\Tests;

use Ineersa\HatfieldExt\CastorLlmMode\CastorCommandRewriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CastorCommandRewriterTest extends TestCase
{
    private const string FOUR_EXPORTS = "export LLM_MODE=true\nexport CASTOR_DISABLE_VERSION_CHECK=1\nexport NO_COLOR=1\nexport CLICOLOR=0";

    #[Test]
    #[DataProvider('isCastorCommandProvider')]
    public function isCastorCommand(string $command, bool $expected): void
    {
        $rewriter = new CastorCommandRewriter();

        $this->assertSame($expected, $rewriter->isCastorCommand($command));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function isCastorCommandProvider(): iterable
    {
        yield 'castor list' => ['castor list', true];
        yield 'vendor/bin/castor' => ['vendor/bin/castor', true];
        yield 'castor at line start' => ['castor', true];
        yield 'xcastor no boundary' => ['xcastor', false];
        yield 'castorfoo no trailing boundary' => ['castorfoo', false];
        yield 'ls' => ['ls', false];
        yield 'echo castor with space' => ['echo castor', true];
    }

    #[Test]
    #[DataProvider('rewriteProvider')]
    public function rewrite(string $input, string $expected): void
    {
        $rewriter = new CastorCommandRewriter();

        $this->assertSame($expected, $rewriter->rewrite($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rewriteProvider(): iterable
    {
        yield 'bare castor list' => [
            'castor list',
            self::FOUR_EXPORTS."\ncastor list --format=md --short --no-ansi",
        ];

        yield 'vendor/bin/castor list' => [
            'vendor/bin/castor list',
            self::FOUR_EXPORTS."\nvendor/bin/castor list --format=md --short --no-ansi",
        ];

        yield 'castor list && castor test' => [
            'castor list && castor test',
            self::FOUR_EXPORTS."\ncastor list --format=md --short --no-ansi && castor test",
        ];

        yield 'castor list && castor list global replace' => [
            'castor list && castor list',
            self::FOUR_EXPORTS."\ncastor list --format=md --short --no-ansi && castor list --format=md --short --no-ansi",
        ];

        yield 'all exports already present' => [
            <<<'CMD'
export LLM_MODE=true
export CASTOR_DISABLE_VERSION_CHECK=1
export NO_COLOR=1
export CLICOLOR=0
castor list
CMD,
            <<<'CMD'
export LLM_MODE=true
export CASTOR_DISABLE_VERSION_CHECK=1
export NO_COLOR=1
export CLICOLOR=0
castor list --format=md --short --no-ansi
CMD,
        ];

        yield 'only LLM_MODE present' => [
            'LLM_MODE=1 castor list',
            "export CASTOR_DISABLE_VERSION_CHECK=1\nexport NO_COLOR=1\nexport CLICOLOR=0\nLLM_MODE=1 castor list --format=md --short --no-ansi",
        ];

        yield 'only CASTOR_DISABLE_VERSION_CHECK present' => [
            'CASTOR_DISABLE_VERSION_CHECK=1 castor list',
            "export LLM_MODE=true\nexport NO_COLOR=1\nexport CLICOLOR=0\nCASTOR_DISABLE_VERSION_CHECK=1 castor list --format=md --short --no-ansi",
        ];

        yield 'castor test not list' => [
            'castor test',
            self::FOUR_EXPORTS."\ncastor test",
        ];
    }
}
