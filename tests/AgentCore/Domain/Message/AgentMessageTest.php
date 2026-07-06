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
        $this->assertNull(AgentMessage::fromPayload([
            'role' => null,
            'content' => null,
        ]));
    }

    public function testFromPayloadReturnsNullWhenRoleIsMissing(): void
    {
        $this->assertNull(AgentMessage::fromPayload([
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

        $this->assertInstanceOf(AgentMessage::class, $message);
        $this->assertSame([
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

        $this->assertInstanceOf(AgentMessage::class, $message);
        $this->assertSame('2026-04-20T10:30:00+00:00', $message->timestamp?->format(\DATE_ATOM));
        $this->assertSame('alice', $message->name);
        $this->assertSame('call-1', $message->toolCallId);
        $this->assertSame('weather', $message->toolName);
        $this->assertSame(['foo' => 'bar'], $message->details);
        $this->assertTrue($message->isError);
        $this->assertSame(['trace' => 'abc'], $message->metadata);
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

        $this->assertInstanceOf(AgentMessage::class, $message);
        $this->assertNull($message->name);
    }

    public function testFromPayloadReturnsNullWhenContentIsNotArray(): void
    {
        $message = AgentMessage::fromPayload([
            'role' => 'assistant',
            'content' => 'plain-text',
        ]);

        $this->assertNull($message);
    }

    /* ─── toArray() ─── */

    public function testToArrayOmitsNullAndEmptyOptionalFields(): void
    {
        $message = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'hello']],
        );

        $payload = $message->toArray();

        $this->assertSame(['role', 'content', 'is_error'], array_keys($payload));
        $this->assertSame('assistant', $payload['role']);
        $this->assertSame([['type' => 'text', 'text' => 'hello']], $payload['content']);
        $this->assertFalse($payload['is_error']);
        $this->assertArrayNotHasKey('timestamp', $payload);
        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('tool_call_id', $payload);
        $this->assertArrayNotHasKey('tool_name', $payload);
        $this->assertArrayNotHasKey('details', $payload);
        $this->assertArrayNotHasKey('metadata', $payload);
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

        $this->assertSame('tool', $payload['role']);
        $this->assertSame([['type' => 'text', 'text' => 'result']], $payload['content']);
        $this->assertTrue($payload['is_error']);
        $this->assertSame('2026-05-15T12:00:00+00:00', $payload['timestamp']);
        $this->assertSame('weather_service', $payload['name']);
        $this->assertSame('call-42', $payload['tool_call_id']);
        $this->assertSame('get_weather', $payload['tool_name']);
        $this->assertSame(['temp' => 22], $payload['details']);
        $this->assertSame(['trace_id' => 'abc123'], $payload['metadata']);
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

        $this->assertInstanceOf(AgentMessage::class, $restored);
        $this->assertSame('assistant', $restored->role);
        $this->assertSame([['type' => 'text', 'text' => 'Hello world']], $restored->content);
        $this->assertSame('2026-06-01T10:00:00+00:00', $restored->timestamp?->format(\DATE_ATOM));
        $this->assertSame('assistant', $restored->name);
        $this->assertNull($restored->toolCallId);
        $this->assertNull($restored->toolName);
        $this->assertSame(['confidence' => 0.95], $restored->details);
        $this->assertFalse($restored->isError);
        $this->assertSame(['source' => 'test'], $restored->metadata);
    }

    /* ─── isCustomRole() ─── */

    #[DataProvider('customRoleProvider')]
    public function testIsCustomRole(string $role, bool $expected): void
    {
        $message = new AgentMessage(role: $role, content: []);

        $this->assertSame($expected, $message->isCustomRole());
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
