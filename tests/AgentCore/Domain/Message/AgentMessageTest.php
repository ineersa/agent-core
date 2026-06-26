<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Message;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testFromPayloadThrowsOnInvalidTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timestamp');

        AgentMessage::fromPayload([
            'role' => 'assistant',
            'content' => [],
            'timestamp' => 'invalid-timestamp',
        ]);
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

    /* ─── toArray() ─── */

    public function testToArrayOmitsNullAndEmptyOptionalFields(): void
    {
        $message = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'hello']],
        );

        $payload = $message->toArray();

        self::assertSame(['role', 'content', 'is_error'], array_keys($payload));
        self::assertSame('assistant', $payload['role']);
        self::assertSame([['type' => 'text', 'text' => 'hello']], $payload['content']);
        self::assertFalse($payload['is_error']);
        self::assertArrayNotHasKey('timestamp', $payload);
        self::assertArrayNotHasKey('name', $payload);
        self::assertArrayNotHasKey('tool_call_id', $payload);
        self::assertArrayNotHasKey('tool_name', $payload);
        self::assertArrayNotHasKey('details', $payload);
        self::assertArrayNotHasKey('metadata', $payload);
    }

    public function testToArrayIncludesAllOptionalFields(): void
    {
        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'result']],
            timestamp: new \DateTimeImmutable('2026-05-15T12:00:00+00:00'),
            name: 'weather_service',
            toolCallId: 'call-42',
            toolName: 'get_weather',
            details: ['temp' => 22],
            isError: true,
            metadata: ['trace_id' => 'abc123'],
        );

        $payload = $message->toArray();

        self::assertSame('tool', $payload['role']);
        self::assertSame([['type' => 'text', 'text' => 'result']], $payload['content']);
        self::assertTrue($payload['is_error']);
        self::assertSame('2026-05-15T12:00:00+00:00', $payload['timestamp']);
        self::assertSame('weather_service', $payload['name']);
        self::assertSame('call-42', $payload['tool_call_id']);
        self::assertSame('get_weather', $payload['tool_name']);
        self::assertSame(['temp' => 22], $payload['details']);
        self::assertSame(['trace_id' => 'abc123'], $payload['metadata']);
    }

    public function testToArrayRoundTripsThroughFromPayload(): void
    {
        $original = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Hello world']],
            timestamp: new \DateTimeImmutable('2026-06-01T10:00:00+00:00'),
            name: 'assistant',
            toolCallId: null,
            toolName: null,
            details: ['confidence' => 0.95],
            isError: false,
            metadata: ['source' => 'test'],
        );

        $payload = $original->toArray();
        $restored = AgentMessage::fromPayload($payload);

        self::assertInstanceOf(AgentMessage::class, $restored);
        self::assertSame('assistant', $restored->role);
        self::assertSame([['type' => 'text', 'text' => 'Hello world']], $restored->content);
        self::assertSame('2026-06-01T10:00:00+00:00', $restored->timestamp?->format(\DATE_ATOM));
        self::assertSame('assistant', $restored->name);
        self::assertNull($restored->toolCallId);
        self::assertNull($restored->toolName);
        self::assertSame(['confidence' => 0.95], $restored->details);
        self::assertFalse($restored->isError);
        self::assertSame(['source' => 'test'], $restored->metadata);
    }

    /* ─── isCustomRole() ─── */

    #[DataProvider('customRoleProvider')]
    public function testIsCustomRole(string $role, bool $expected): void
    {
        $message = new AgentMessage(role: $role, content: []);

        self::assertSame($expected, $message->isCustomRole());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function customRoleProvider(): array
    {
        return [
            'system' => ['system', false],
            'user' => ['user', false],
            'assistant' => ['assistant', false],
            'tool' => ['tool', false],
            'developer' => ['developer', true],
            'critic' => ['critic', true],
            'function' => ['function', true],
            'model' => ['model', true],
        ];
    }
}
