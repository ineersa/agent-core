<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Auth;

use Ineersa\CodingAgent\Auth\ManualCodeParser;
use PHPUnit\Framework\TestCase;

final class ManualCodeParserTest extends TestCase
{
    public function testParsesFullRedirectUrl(): void
    {
        $result = ManualCodeParser::parse(
            'http://localhost:1455/auth/callback?code=abc123&state=def456',
        );

        self::assertSame('abc123', $result['code']);
        self::assertSame('def456', $result['state']);
    }

    public function testParsesUrlWithOnlyCode(): void
    {
        $result = ManualCodeParser::parse(
            'http://localhost:1455/auth/callback?code=abc123',
        );

        self::assertSame('abc123', $result['code']);
        self::assertNull($result['state']);
    }

    public function testParsesCodeAndStateAsQueryString(): void
    {
        $result = ManualCodeParser::parse('code=abc123&state=def456');

        self::assertSame('abc123', $result['code']);
        self::assertSame('def456', $result['state']);
    }

    public function testParsesBareCodeHashState(): void
    {
        $result = ManualCodeParser::parse('abc123#def456');

        self::assertSame('abc123', $result['code']);
        self::assertSame('def456', $result['state']);
    }

    public function testParsesBareCode(): void
    {
        $result = ManualCodeParser::parse('axbYz9876');

        self::assertSame('axbYz9876', $result['code']);
        self::assertNull($result['state']);
    }

    public function testReturnsEmptyForEmptyInput(): void
    {
        $result = ManualCodeParser::parse('');

        self::assertNull($result['code']);
        self::assertNull($result['state']);
    }

    public function testReturnsEmptyForWhitespaceOnly(): void
    {
        $result = ManualCodeParser::parse("  \n  ");

        self::assertNull($result['code']);
        self::assertNull($result['state']);
    }
}
