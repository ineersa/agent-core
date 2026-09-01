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

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: [],
        ));

        $this->assertFalse($emitter->isShuttingDown());
    }

    public function testShutdownSetsFlag(): void
    {
        $emitter = $this->createEmitter();
        $this->assertFalse($emitter->isShuttingDown());

        $emitter->shutdown();
        $this->assertTrue($emitter->isShuttingDown());
    }

    public function testEmitWritesJsonlToStdout(): void
    {
        $emitter = $this->createEmitter();
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: 'stdout-run-1',
            seq: 1,
            payload: [],
        ));

        $stdout = $this->stdoutHandle($emitter);
        rewind($stdout);
        $raw = stream_get_contents($stdout) ?: '';

        $this->assertStringContainsString('run.started', $raw);
        $this->assertStringContainsString('stdout-run-1', $raw);
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
