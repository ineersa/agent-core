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
    public function testEncodeAndDecodeCommand(): void
    {
        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'start_run',
            payload: ['prompt' => 'Hello', 'options' => []],
        );

        $line = JsonlCodec::encodeCommand($command);
        $this->assertStringEndsWith("\n", $line);

        $decoded = JsonlCodec::decodeCommand($line);
        $this->assertSame('cmd_1', $decoded->id);
        $this->assertSame('start_run', $decoded->type);
        $this->assertSame('Hello', $decoded->payload['prompt']);
    }

    public function testEncodeAndDecodeEvent(): void
    {
        $event = new RuntimeEvent(
            type: 'message_delta',
            runId: 'run_abc',
            seq: 12,
            payload: ['text' => 'Hello world'],
        );

        $line = JsonlCodec::encodeEvent($event);
        $this->assertStringEndsWith("\n", $line);

        $decoded = JsonlCodec::decodeEvent($line);
        $this->assertSame('message_delta', $decoded->type);
        $this->assertSame('run_abc', $decoded->runId);
        $this->assertSame(12, $decoded->seq);
        $this->assertSame('Hello world', $decoded->payload['text']);
    }

    public function testEncodeRoundtripPreservesAllFields(): void
    {
        $event = new RuntimeEvent(
            type: 'run_started',
            runId: 'run_xyz',
            seq: 1,
            payload: ['status' => 'running', 'started_at' => '2026-01-01T00:00:00+00:00'],
        );

        $line = JsonlCodec::encodeEvent($event);
        $decoded = JsonlCodec::decodeEvent(trim($line));

        $this->assertSame($event->v, $decoded->v);
        $this->assertSame($event->type, $decoded->type);
        $this->assertSame($event->runId, $decoded->runId);
        $this->assertSame($event->seq, $decoded->seq);
        $this->assertSame($event->payload, $decoded->payload);
    }

    public function testDecodeCommandWithRunId(): void
    {
        $line = "{\"v\":1,\"id\":\"cmd_2\",\"type\":\"user_message\",\"runId\":\"run_123\",\"payload\":{\"text\":\"Hi\"}}\n";
        $command = JsonlCodec::decodeCommand($line);

        $this->assertSame('cmd_2', $command->id);
        $this->assertSame('user_message', $command->type);
        $this->assertSame('run_123', $command->runId);
        $this->assertSame('Hi', $command->payload['text']);
    }

    public function testDecodeEventWithoutNewline(): void
    {
        $line = '{"v":1,"type":"run_finished","runId":"run_123","seq":99,"payload":{}}';
        $event = JsonlCodec::decodeEvent($line);

        $this->assertSame('run_finished', $event->type);
        $this->assertSame('run_123', $event->runId);
        $this->assertSame(99, $event->seq);
    }

    public function testDecodeEmptyLineThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty line');
        JsonlCodec::decodeEvent('');
    }

    public function testDecodeWhitespaceLineThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty line');
        JsonlCodec::decodeEvent("   \n");
    }

    public function testDecodeInvalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        JsonlCodec::decodeEvent("not json\n");
    }

    public function testCommandWithNullRunId(): void
    {
        $command = new RuntimeCommand(
            id: 'cmd_3',
            type: 'start_run',
            payload: ['prompt' => 'test'],
        );

        $this->assertNull($command->runId);

        $line = JsonlCodec::encodeCommand($command);
        $decoded = JsonlCodec::decodeCommand($line);

        $this->assertNull($decoded->runId);
    }
}
