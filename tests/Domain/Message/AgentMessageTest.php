<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use PHPUnit\Framework\TestCase;

final class AgentMessageTest extends TestCase
{
    public function testFromPayloadReturnsNullWhenRequiredFieldsAreNull(): void
    {
        self::assertNull(AgentMessage::fromPayload([
            'role' => null,
            'content' => null,
        ]));
    }

    public function testFromPayloadReturnsNullWhenRoleIsMissing(): void
    {
        self::assertNull(AgentMessage::fromPayload([
            'content' => [],
        ]));
    }

    public function testFromPayloadFiltersNonArrayContentParts(): void
    {
        $message = AgentMessage::fromPayload([
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'hello'],
                'ignored',
                42,
                ['type' => 'tool_call', 'name' => 'search'],
            ],
        ]);

        self::assertInstanceOf(AgentMessage::class, $message);
        self::assertSame([
            ['type' => 'text', 'text' => 'hello'],
            ['type' => 'tool_call', 'name' => 'search'],
        ], $message->content);
    }

    public function testFromPayloadParsesValidTimestampAndOptionalFields(): void
    {
        $message = AgentMessage::fromPayload([
            'role' => 'user',
            'content' => [],
            'timestamp' => '2026-04-20T10:30:00+00:00',
            'name' => 'alice',
            'tool_call_id' => 'call-1',
            'tool_name' => 'weather',
            'details' => ['foo' => 'bar'],
            'is_error' => true,
            'metadata' => ['trace' => 'abc'],
        ]);

        self::assertInstanceOf(AgentMessage::class, $message);
        self::assertSame('2026-04-20T10:30:00+00:00', $message->timestamp?->format(\DATE_ATOM));
        self::assertSame('alice', $message->name);
        self::assertSame('call-1', $message->toolCallId);
        self::assertSame('weather', $message->toolName);
        self::assertSame(['foo' => 'bar'], $message->details);
        self::assertTrue($message->isError);
        self::assertSame(['trace' => 'abc'], $message->metadata);
    }

    public function testFromPayloadSetsTimestampToNullWhenInvalid(): void
    {
        $message = AgentMessage::fromPayload([
            'role' => 'assistant',
            'content' => [],
            'timestamp' => 'invalid-timestamp',
        ]);

        self::assertInstanceOf(AgentMessage::class, $message);
        self::assertNull($message->timestamp);
    }

    public function testFromPayloadSetsNameToNullWhenNotString(): void
    {
        $message = AgentMessage::fromPayload([
            'role' => 'assistant',
            'content' => [],
            'name' => ['not', 'a', 'string'],
        ]);

        self::assertInstanceOf(AgentMessage::class, $message);
        self::assertNull($message->name);
    }

    public function testFromPayloadReturnsNullWhenContentIsNotArray(): void
    {
        $message = AgentMessage::fromPayload([
            'role' => 'assistant',
            'content' => 'plain-text',
        ]);

        self::assertNull($message);
    }
}
