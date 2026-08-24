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
    public static function setUpBeforeClass(): void
    {
        stream_wrapper_register('jsonl-test', JsonlCodecShortWriteStreamWrapper::class);
    }

    public static function tearDownAfterClass(): void
    {
        stream_wrapper_unregister('jsonl-test');
    }

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

    public function testWriteCompletesShortPositiveWrites(): void
    {
        JsonlCodecShortWriteStreamWrapper::$output = '';
        JsonlCodecShortWriteStreamWrapper::$writeLimit = 2;
        JsonlCodecShortWriteStreamWrapper::$writeResult = null;
        $stream = fopen('jsonl-test://short-write', 'wb');
        $this->assertIsResource($stream);

        $this->assertTrue(JsonlCodec::write($stream, "abcdef\n"));
        $this->assertSame("abcdef\n", JsonlCodecShortWriteStreamWrapper::$output);
        fclose($stream);
    }

    public function testWriteFailsForZeroOrFalseWrite(): void
    {
        $stream = fopen('jsonl-test://failed-write', 'wb');
        $this->assertIsResource($stream);

        JsonlCodecShortWriteStreamWrapper::$writeResult = 0;
        $this->assertFalse(JsonlCodec::write($stream, "x\n"));

        JsonlCodecShortWriteStreamWrapper::$writeResult = false;
        $this->assertFalse(JsonlCodec::write($stream, "x\n"));
        fclose($stream);
    }
}

/** @internal */
final class JsonlCodecShortWriteStreamWrapper
{
    public mixed $context;

    public static string $output = '';

    public static int $writeLimit = 1;

    public static int|false|null $writeResult = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int|false
    {
        if (null !== self::$writeResult) {
            return self::$writeResult;
        }

        $chunk = substr($data, 0, self::$writeLimit);
        self::$output .= $chunk;

        return \strlen($chunk);
    }
}
