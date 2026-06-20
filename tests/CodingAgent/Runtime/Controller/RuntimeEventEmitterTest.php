<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter
 */
final class RuntimeEventEmitterTest extends TestCase
{
    private function createEmitter(): RuntimeEventEmitter
    {
        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $logger = $this->createStub(LoggerInterface::class);

        return new RuntimeEventEmitter(
            eventClient: null,
            boundary: $boundary,
            logger: $logger,
        );
    }

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

    public function testEmitWithNullPersisterDoesNotThrow(): void
    {
        $emitter = $this->createEmitter();

        // Emit with a full round-trip — no persister, no event client.
        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: 'test-run',
            seq: 1,
            payload: [],
        ));

        $this->assertFalse($emitter->isShuttingDown());
    }
}
