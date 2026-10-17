<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter
 */
final class RuntimeEventEmitterTest extends TestCase
{
    public function testOpenStdoutOpensWritableStream(): void
    {
        $emitter = $this->createEmitter();
        $emitter->openStdout();

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: [],
        ));

        $this->assertFalse($emitter->isShuttingDown());
    }

    public function testEmitWithoutOpenStdoutDoesNotThrow(): void
    {
        $emitter = $this->createEmitter();

        $this->assertFalse($emitter->tryEmit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: [],
        )));

        $this->assertFalse($emitter->isShuttingDown());
    }

    public function testShutdownSetsFlag(): void
    {
        $emitter = $this->createEmitter();
        $this->assertFalse($emitter->isShuttingDown());

        $emitter->shutdown();
        $this->assertTrue($emitter->isShuttingDown());
        $this->assertFalse($emitter->tryEmit(new RuntimeEvent(RuntimeEventTypeEnum::ToolQuestionRequested->value, 'run', 0, [])));
    }

    public function testEmitWritesJsonlToStdout(): void
    {
        $emitter = $this->createEmitter();
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $this->assertTrue($emitter->tryEmit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: 'stdout-run-1',
            seq: 1,
            payload: [],
        )));

        $stdout = $this->stdoutHandle($emitter);
        rewind($stdout);
        $raw = stream_get_contents($stdout) ?: '';

        $this->assertStringContainsString('run.started', $raw);
        $this->assertStringContainsString('stdout-run-1', $raw);
    }

    public function testBootstrapOutputIsBoundedAndCancellationReleasesPendingCallbacks(): void
    {
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $emitter = $this->createEmitter();
        $reflection = new \ReflectionClass($emitter);
        $reflection->getProperty('stdout')->setValue($emitter, $writer);
        $emitter->beginBootstrapOutput();
        $token = new \stdClass();
        $weak = \WeakReference::create($token);
        $frame = new RuntimeEvent(RuntimeEventTypeEnum::BootstrapFrame->value, 'run', 0, ['data' => base64_encode(str_repeat('x', 32768))]);
        try {
            $emitter->emitTransfer($frame, static fn () => $token);
            unset($token);
            $this->assertNotNull($weak->get());
            try {
                $emitter->emitTransfer($frame);
                $this->fail('A second frame cannot exceed the 64 KiB pending budget.');
            } catch (\Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException $exception) {
                $this->assertStringContainsString('bounded pending budget', $exception->getMessage());
            }
            $this->assertLessThanOrEqual(65536, $reflection->getProperty('pendingBytes')->getValue($emitter));
            $emitter->cancelBootstrapOutput();
            $this->assertSame([], $reflection->getProperty('pending')->getValue($emitter));
            $this->assertSame(0, $reflection->getProperty('pendingBytes')->getValue($emitter));
            $this->assertNull($reflection->getProperty('writeWatcher')->getValue($emitter));
            $this->assertNull($weak->get());
            $this->assertFalse($emitter->tryEmit(new RuntimeEvent(RuntimeEventTypeEnum::RunStarted->value, 'run', 99, [])));
            $this->assertSame(0, $reflection->getProperty('pendingBytes')->getValue($emitter), 'A cancelled view remains detached.');
        } finally {
            $emitter->shutdown();
            fclose($writer);
            fclose($reader);
        }
    }

    public function testDisconnectReleasesPendingOutputAndInvokesOwnedShutdown(): void
    {
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $emitter = $this->createEmitter();
        $reflection = new \ReflectionClass($emitter);
        $reflection->getProperty('stdout')->setValue($emitter, $writer);
        $emitter->beginBootstrapOutput();
        $stopped = false;
        $emitter->setFatalShutdownHandler(static function () use (&$stopped): void { $stopped = true; });
        try {
            $emitter->emitTransfer(new RuntimeEvent(RuntimeEventTypeEnum::BootstrapEnd->value, 'run', 0, []));
            fclose($reader);
            $reflection->getMethod('flushBootstrap')->invoke($emitter);
            $this->assertTrue($stopped);
            $this->assertTrue($emitter->isShuttingDown());
            $this->assertSame(0, $reflection->getProperty('pendingBytes')->getValue($emitter));
            $this->assertNull($reflection->getProperty('writeWatcher')->getValue($emitter));
        } finally {
            $emitter->shutdown();
            fclose($writer);
        }
    }

    public function testTerminalDrainFinishesPartialLineWithoutBootstrapSuccessCallbacks(): void
    {
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $emitter = $this->createEmitter();
        $reflection = new \ReflectionClass($emitter);
        $reflection->getProperty('stdout')->setValue($emitter, $writer);
        $emitter->beginBootstrapOutput();
        $completed = false;
        $after = static function () use (&$completed): void { $completed = true; };
        $emptyFrame = new RuntimeEvent(RuntimeEventTypeEnum::BootstrapFrame->value, 'run', 0, ['data' => '']);
        $frame = new RuntimeEvent(RuntimeEventTypeEnum::BootstrapFrame->value, 'run', 0, ['data' => str_repeat('x', 65536 - \strlen(JsonlCodec::encodeEvent($emptyFrame)))]);
        $wire = '';
        stream_set_blocking($reader, false);
        $readWatcher = EventLoop::onReadable($reader, static function () use ($reader, &$wire): void { $wire .= stream_get_contents($reader); });
        try {
            $emitter->emitTransfer($frame, $after);
            $pending = $reflection->getProperty('pending')->getValue($emitter);
            // Deterministically model a kernel write ending inside a JSONL frame.
            fwrite($writer, substr($pending[0]['line'], 0, 10));
            $pending[0]['line'] = substr($pending[0]['line'], 10);
            $pending[0]['started'] = true;
            $reflection->getProperty('pending')->setValue($emitter, $pending);
            $reflection->getProperty('pendingBytes')->setValue($emitter, \strlen($pending[0]['line']));
            $emitter->whenDrained($after);
            $emitter->shutdown(microtime(true) + 1, new RuntimeEvent(RuntimeEventTypeEnum::ProtocolError->value, '', 0, ['error' => 'broker died', 'fatal' => true]));
            $wire .= stream_get_contents($reader);
            $lines = explode("\n", trim($wire));
            $this->assertCount(2, $lines);
            $first = json_decode($lines[0], true, 16, \JSON_THROW_ON_ERROR);
            $last = json_decode($lines[1], true, 16, \JSON_THROW_ON_ERROR);
            $this->assertSame('bootstrap.frame', $first['type']);
            $this->assertSame('protocol.error', $last['type']);
            $this->assertSame('broker died', $last['payload']['error']);
            $this->assertFalse($completed, 'Fatal draining cannot acknowledge bootstrap success.');
            $this->assertTrue($emitter->isShuttingDown());
            $this->assertSame([], $reflection->getProperty('pending')->getValue($emitter));
        } finally {
            EventLoop::cancel($readWatcher);
            $emitter->shutdown();
            fclose($writer);
            fclose($reader);
        }
    }

    public function testTerminalDrainSuppressesAlreadyQueuedBootstrapCallbacks(): void
    {
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $emitter = $this->createEmitter();
        $reflection = new \ReflectionClass($emitter);
        $reflection->getProperty('stdout')->setValue($emitter, $writer);
        $emitter->beginBootstrapOutput();
        $completed = false;
        try {
            $emitter->emitTransfer(new RuntimeEvent(RuntimeEventTypeEnum::BootstrapEnd->value, 'run', 0, []), static function () use (&$completed): void { $completed = true; });
            $reflection->getMethod('flushBootstrap')->invoke($emitter);
            $emitter->emitTransfer(new RuntimeEvent(RuntimeEventTypeEnum::SessionReady->value, 'run', 0, []));
            $emitter->shutdown(microtime(true) + 1, new RuntimeEvent(RuntimeEventTypeEnum::ProtocolError->value, '', 0, ['fatal' => true]));
            $this->assertFalse($completed, 'The callback queued before shutdown must not resume a successful attachment.');
            stream_set_blocking($reader, false);
            $this->assertStringNotContainsString('session.ready', stream_get_contents($reader));
        } finally {
            $emitter->shutdown();
            fclose($writer);
            fclose($reader);
        }
    }

    public function testTerminalDrainReleasesBackpressuredOutputAtDeadline(): void
    {
        [$writer, $reader] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('session.terminal_output_failed', $this->isArray());
        $emitter = new RuntimeEventEmitter($logger);
        $reflection = new \ReflectionClass($emitter);
        $reflection->getProperty('stdout')->setValue($emitter, $writer);
        $emitter->beginBootstrapOutput();
        try {
            // Fill a real output buffer without relying on a delayed reader.
            $written = 0;
            for ($attempt = 0; $attempt < 16; ++$attempt) {
                $written = fwrite($writer, str_repeat('x', 65536));
                if (0 === $written) {
                    break;
                }
            }
            $this->assertSame(0, $written, 'The socket must be backpressured before testing the deadline.');
            $emitter->shutdown(microtime(true), new RuntimeEvent(RuntimeEventTypeEnum::ProtocolError->value, '', 0, ['fatal' => true]));
            $this->assertTrue($emitter->isShuttingDown());
            $this->assertSame([], $reflection->getProperty('pending')->getValue($emitter));
            $this->assertNull($reflection->getProperty('writeWatcher')->getValue($emitter));
        } finally {
            $emitter->shutdown();
            fclose($writer);
            fclose($reader);
        }
    }

    private function createEmitter(): RuntimeEventEmitter
    {
        return new RuntimeEventEmitter($this->createStub(LoggerInterface::class));
    }

    private function replaceStdoutWithMemory(RuntimeEventEmitter $emitter): void
    {
        $ref = new \ReflectionClass($emitter);
        $prop = $ref->getProperty('stdout');
        $memory = fopen('php://memory', 'w+b');
        $this->assertIsResource($memory);
        $prop->setValue($emitter, $memory);
    }

    /** @return resource */
    private function stdoutHandle(RuntimeEventEmitter $emitter): mixed
    {
        $ref = new \ReflectionClass($emitter);
        $prop = $ref->getProperty('stdout');
        $stdout = $prop->getValue($emitter);
        $this->assertIsResource($stdout);

        return $stdout;
    }
}
