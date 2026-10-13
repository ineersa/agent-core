<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
