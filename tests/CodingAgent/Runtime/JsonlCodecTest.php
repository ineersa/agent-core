<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonlCodec::class)]
#[CoversClass(RuntimeCommand::class)]
#[CoversClass(RuntimeEvent::class)]
final class JsonlCodecTest extends TestCase
{
    public function test_encode_and_decode_command(): void
    {
        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'start_run',
            payload: ['prompt' => 'Hello', 'options' => []],
        );

        $line = JsonlCodec::encodeCommand($command);
        self::assertStringEndsWith("\n", $line);

        $decoded = JsonlCodec::decodeCommand($line);
        self::assertSame('cmd_1', $decoded->id);
        self::assertSame('start_run', $decoded->type);
        self::assertSame('Hello', $decoded->payload['prompt']);
    }

    public function test_encode_and_decode_event(): void
    {
        $event = new RuntimeEvent(
            type: 'message_delta',
            runId: 'run_abc',
            seq: 12,
            payload: ['text' => 'Hello world'],
        );

        $line = JsonlCodec::encodeEvent($event);
        self::assertStringEndsWith("\n", $line);

        $decoded = JsonlCodec::decodeEvent($line);
        self::assertSame('message_delta', $decoded->type);
        self::assertSame('run_abc', $decoded->runId);
        self::assertSame(12, $decoded->seq);
        self::assertSame('Hello world', $decoded->payload['text']);
    }

    public function test_encode_roundtrip_preserves_all_fields(): void
    {
        $event = new RuntimeEvent(
            type: 'run_started',
            runId: 'run_xyz',
            seq: 1,
            payload: ['status' => 'running', 'started_at' => '2026-01-01T00:00:00+00:00'],
        );

        $line = JsonlCodec::encodeEvent($event);
        $decoded = JsonlCodec::decodeEvent(trim($line));

        self::assertSame($event->v, $decoded->v);
        self::assertSame($event->type, $decoded->type);
        self::assertSame($event->runId, $decoded->runId);
        self::assertSame($event->seq, $decoded->seq);
        self::assertSame($event->payload, $decoded->payload);
    }

    public function test_decode_command_with_runId(): void
    {
        $line = "{\"v\":1,\"id\":\"cmd_2\",\"type\":\"user_message\",\"runId\":\"run_123\",\"payload\":{\"text\":\"Hi\"}}\n";
        $command = JsonlCodec::decodeCommand($line);

        self::assertSame('cmd_2', $command->id);
        self::assertSame('user_message', $command->type);
        self::assertSame('run_123', $command->runId);
        self::assertSame('Hi', $command->payload['text']);
    }

    public function test_decode_event_without_newline(): void
    {
        $line = '{"v":1,"type":"run_finished","runId":"run_123","seq":99,"payload":{}}';
        $event = JsonlCodec::decodeEvent($line);

        self::assertSame('run_finished', $event->type);
        self::assertSame('run_123', $event->runId);
        self::assertSame(99, $event->seq);
    }

    public function test_decode_empty_line_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty line');
        JsonlCodec::decodeEvent('');
    }

    public function test_decode_whitespace_line_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty line');
        JsonlCodec::decodeEvent("   \n");
    }

    public function test_decode_invalid_json_throws(): void
    {
        $this->expectException(\JsonException::class);
        JsonlCodec::decodeEvent("not json\n");
    }

    public function test_command_with_null_runId(): void
    {
        $command = new RuntimeCommand(
            id: 'cmd_3',
            type: 'start_run',
            payload: ['prompt' => 'test'],
        );

        self::assertNull($command->runId);

        $line = JsonlCodec::encodeCommand($command);
        $decoded = JsonlCodec::decodeCommand($line);

        self::assertNull($decoded->runId);
    }
}
