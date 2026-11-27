<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\MessageContentText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageContentTextTest extends TestCase
{
    private const array CONTENT = [
        ['type' => 'text', 'text' => 'first'],
        'not-a-part',
        ['type' => 'thinking', 'text' => 'untyped'],
        ['text' => 'missing type'],
        ['type' => 'text', 'text' => ''],
        ['type' => 'text', 'text' => 42],
        ['type' => 'text'],
        ['type' => 'image_ref', 'path' => '/tmp/a.png'],
        ['type' => 'text', 'text' => 'last'],
    ];

    public static function joinCases(): iterable
    {
        yield 'any part with text' => [false, "first\nuntyped\nmissing type\nlast"];
        yield 'text parts only' => [true, "first\nlast"];
    }

    #[DataProvider('joinCases')]
    public function testJoinKeepsNonEmptyStringTextWithNewlines(bool $textPartsOnly, string $expected): void
    {
        $this->assertSame($expected, MessageContentText::join(self::CONTENT, $textPartsOnly));
    }

    public function testJoinReturnsEmptyStringWithoutText(): void
    {
        $this->assertSame('', MessageContentText::join([], textPartsOnly: true));
        $this->assertSame('', MessageContentText::join([['type' => 'text', 'text' => '']], textPartsOnly: false));
    }
}
